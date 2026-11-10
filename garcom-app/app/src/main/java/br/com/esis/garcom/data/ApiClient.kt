package br.com.esis.garcom.data

import br.com.esis.garcom.ApiConfig
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import retrofit2.Retrofit
import retrofit2.converter.kotlinx.serialization.asConverterFactory
import java.util.concurrent.TimeUnit

/** Avisa a tela quando o servidor recusa o token (401): a sessão acabou e o app volta ao login. */
object SessionEvents {
    private val expiredFlow = MutableSharedFlow<Unit>(extraBufferCapacity = 1)
    val expired: SharedFlow<Unit> = expiredFlow

    fun notifyExpired() {
        expiredFlow.tryEmit(Unit)
    }
}

object ApiClient {
    val json = Json { ignoreUnknownKeys = true }

    fun service(tokenStore: TokenStore): ApiService {
        val http = OkHttpClient.Builder()
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(20, TimeUnit.SECONDS)
            .addInterceptor { chain ->
                val token = runBlocking { tokenStore.read() }
                val request = chain.request().newBuilder()
                    .header("Accept", "application/json")
                    .apply { if (token != null) header("Authorization", "Bearer $token") }
                    .build()
                val response = chain.proceed(request)
                if (response.code == 401 && token != null) {
                    runBlocking { tokenStore.clear() }
                    SessionEvents.notifyExpired()
                }
                response
            }
            .build()

        return Retrofit.Builder()
            .baseUrl(ApiConfig.baseUrl())
            .client(http)
            .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
            .build()
            .create(ApiService::class.java)
    }
}
