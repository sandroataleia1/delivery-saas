package br.com.esis.garcom.data

import kotlinx.coroutines.CancellationException
import retrofit2.HttpException

interface AuthRepository {
    suspend fun login(email: String, password: String): LoginResult
    suspend fun me(): Waiter?
    suspend fun logout()

    /** Versão mínima do app exigida pelo servidor. Nulo se não der para saber: nesse caso o app segue normal. */
    suspend fun minVersionCode(): Int?
}

class ApiAuthRepository(
    private val api: ApiService,
    private val tokenStore: TokenStore,
    private val waiterCache: WaiterCache,
    private val deviceName: String,
) : AuthRepository {
    override suspend fun login(email: String, password: String): LoginResult {
        val response = try {
            api.login(LoginRequest(email, password, deviceName))
        } catch (error: CancellationException) {
            throw error
        } catch (error: HttpException) {
            val message = serverMessage(error)
            return when (error.code()) {
                403 -> LoginResult.NotAWaiter(message ?: "Seu perfil não atende mesas.")
                in 500..599 -> LoginResult.InvalidCredentials("O servidor está com problema. Tente de novo em instantes.")
                else -> LoginResult.InvalidCredentials(message ?: "Não foi possível entrar.")
            }
        } catch (error: Exception) {
            // Sem rede, ou uma resposta que não é da API (Wi-Fi com página de login, proxy).
            return LoginResult.NoConnection
        }

        val waiter = response.user.toWaiter()
        return try {
            tokenStore.save(response.token)
            waiterCache.saveWaiter(waiter)
            LoginResult.Success(waiter)
        } catch (error: CancellationException) {
            throw error
        } catch (error: Exception) {
            runCatching { tokenStore.clear() }
            LoginResult.InvalidCredentials("Não foi possível salvar o acesso neste celular. Tente de novo.")
        }
    }

    /** Confere a sessão salva. Sem internet, mantém o garçom no salão com o último cadastro conhecido. */
    override suspend fun me(): Waiter? {
        val token = try {
            tokenStore.read()
        } catch (error: CancellationException) {
            throw error
        } catch (error: Exception) {
            null
        } ?: return null

        return try {
            api.me().data.toWaiter().also { waiterCache.saveWaiter(it) }
        } catch (error: CancellationException) {
            throw error
        } catch (error: HttpException) {
            if (error.code() == 401) {
                tokenStore.clear()
                null
            } else {
                cachedWaiter()
            }
        } catch (error: Exception) {
            cachedWaiter()
        }
    }

    override suspend fun logout() {
        try {
            api.logout()
        } catch (error: CancellationException) {
            throw error
        } catch (error: Exception) {
            // Sem internet o token fica no servidor, mas sai do aparelho; o admin pode revogá-lo depois.
        }
        runCatching { tokenStore.clear() }
    }

    override suspend fun minVersionCode(): Int? = try {
        api.app().minVersionCode
    } catch (error: CancellationException) {
        throw error
    } catch (error: Exception) {
        null
    }

    private suspend fun cachedWaiter(): Waiter? = runCatching { waiterCache.readWaiter() }.getOrNull()

    private fun serverMessage(error: HttpException): String? = runCatching {
        error.response()?.errorBody()?.string()?.let { ApiClient.json.decodeFromString(ApiError.serializer(), it).message }
    }.getOrNull()
}
