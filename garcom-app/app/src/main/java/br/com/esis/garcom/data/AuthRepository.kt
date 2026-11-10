package br.com.esis.garcom.data

import retrofit2.HttpException
import java.io.IOException

interface AuthRepository {
    suspend fun login(email: String, password: String): LoginResult
    suspend fun me(): Waiter?
    suspend fun logout()
}

class ApiAuthRepository(
    private val api: ApiService,
    private val tokenStore: TokenStore,
    private val waiterCache: WaiterCache,
    private val deviceName: String,
) : AuthRepository {
    override suspend fun login(email: String, password: String): LoginResult = try {
        val response = api.login(LoginRequest(email, password, deviceName))
        val waiter = response.user.toWaiter()
        tokenStore.save(response.token)
        waiterCache.saveWaiter(waiter)
        LoginResult.Success(waiter)
    } catch (error: HttpException) {
        val message = serverMessage(error)
        when (error.code()) {
            403 -> LoginResult.NotAWaiter(message ?: "Seu perfil não atende mesas.")
            in 500..599 -> LoginResult.InvalidCredentials("O servidor está com problema. Tente de novo em instantes.")
            else -> LoginResult.InvalidCredentials(message ?: "Não foi possível entrar.")
        }
    } catch (error: IOException) {
        LoginResult.NoConnection
    }

    /** Confere a sessão salva. Sem internet, mantém o garçom no salão com o último cadastro conhecido. */
    override suspend fun me(): Waiter? {
        if (tokenStore.read() == null) {
            return null
        }

        return try {
            api.me().data.toWaiter().also { waiterCache.saveWaiter(it) }
        } catch (error: HttpException) {
            if (error.code() == 401) {
                tokenStore.clear()
                null
            } else {
                waiterCache.readWaiter()
            }
        } catch (error: IOException) {
            waiterCache.readWaiter()
        }
    }

    override suspend fun logout() {
        try {
            api.logout()
        } catch (error: Exception) {
            // Sem internet o token fica no servidor, mas sai do aparelho; o admin pode revogá-lo depois.
        }
        tokenStore.clear()
    }

    private fun serverMessage(error: HttpException): String? = runCatching {
        error.response()?.errorBody()?.string()?.let { ApiClient.json.decodeFromString(ApiError.serializer(), it).message }
    }.getOrNull()
}
