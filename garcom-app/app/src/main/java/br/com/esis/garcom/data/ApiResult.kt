package br.com.esis.garcom.data

import kotlinx.coroutines.CancellationException
import retrofit2.HttpException

/** Resultado de uma chamada da comanda ou do cardápio. 422 traz a mensagem do servidor para mostrar ao garçom. */
sealed interface ApiResult<out T> {
    data class Ok<T>(val value: T) : ApiResult<T>
    data class Rejected(val message: String) : ApiResult<Nothing>
    data object NotFound : ApiResult<Nothing>
    data object NoConnection : ApiResult<Nothing>
    data object ServerError : ApiResult<Nothing>
}

internal suspend fun <T> apiCall(block: suspend () -> T): ApiResult<T> = try {
    ApiResult.Ok(block())
} catch (error: CancellationException) {
    throw error
} catch (error: HttpException) {
    when (error.code()) {
        422 -> ApiResult.Rejected(serverMessage(error) ?: "Não foi possível concluir. Confira e tente de novo.")
        404 -> ApiResult.NotFound
        else -> ApiResult.ServerError
    }
} catch (error: Exception) {
    // Sem rede, ou uma resposta que não é da API (Wi-Fi com página de login, proxy).
    ApiResult.NoConnection
}

private fun serverMessage(error: HttpException): String? = runCatching {
    error.response()?.errorBody()?.string()?.let { ApiClient.json.decodeFromString(ApiError.serializer(), it).message }
}.getOrNull()
