package br.com.esis.garcom.data

import kotlinx.coroutines.test.runTest
import kotlinx.serialization.SerializationException
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import retrofit2.Response

/** Respostas que não são JSON (Wi-Fi com portal, proxy) e falhas do armazenamento não podem fechar o app. */
class RepositoryFailureTest {
    private val waiterDto = WaiterDto(7, "Ana", CompanyDto(1, "Isa Lanches"))

    private class FakeApi(
        val onLogin: () -> LoginResponse = { error("não usado") },
        val onMe: () -> WaiterEnvelope = { error("não usado") },
        val onTables: () -> TablesEnvelope = { error("não usado") },
    ) : ApiService {
        override suspend fun login(body: LoginRequest): LoginResponse = onLogin()
        override suspend fun me(): WaiterEnvelope = onMe()
        override suspend fun logout(): Response<Unit> = Response.success(Unit)
        override suspend fun tables(): TablesEnvelope = onTables()
    }

    private class FakeSession(
        var token: String? = "abc",
        val failOnSave: Boolean = false,
        val failOnRead: Boolean = false,
    ) : TokenStore, WaiterCache {
        var waiter: Waiter? = null

        override suspend fun read(): String? = if (failOnRead) throw IllegalStateException("DataStore corrompido") else token
        override suspend fun save(token: String) {
            if (failOnSave) throw IllegalStateException("Keystore inválido")
            this.token = token
        }
        override suspend fun clear() {
            token = null
        }
        override suspend fun readWaiter(): Waiter? = waiter
        override suspend fun saveWaiter(waiter: Waiter) {
            this.waiter = waiter
        }
    }

    private fun auth(api: ApiService, session: FakeSession) = ApiAuthRepository(api, session, session, "Moto G")

    @Test
    fun salao_com_resposta_que_nao_e_json_mostra_aviso() = runTest {
        val repository = ApiTableRepository(FakeApi(onTables = { throw SerializationException("<html>") }))

        assertEquals(TablesResult.NoConnection, repository.tables())
    }

    @Test
    fun login_com_resposta_que_nao_e_json_mostra_sem_conexao() = runTest {
        val result = auth(FakeApi(onLogin = { throw SerializationException("<html>") }), FakeSession(token = null)).login("a@b.c", "x")

        assertEquals(LoginResult.NoConnection, result)
    }

    @Test
    fun login_quando_o_celular_nao_salva_o_acesso_mostra_erro() = runTest {
        val session = FakeSession(token = null, failOnSave = true)

        val result = auth(FakeApi(onLogin = { LoginResponse("tok", waiterDto) }), session).login("a@b.c", "x")

        assertTrue(result is LoginResult.InvalidCredentials)
        assertNull(session.token)
    }

    @Test
    fun abrir_com_resposta_que_nao_e_json_usa_o_ultimo_garcom() = runTest {
        val session = FakeSession().apply { waiter = waiterDto.toWaiter() }

        val waiter = auth(FakeApi(onMe = { throw SerializationException("<html>") }), session).me()

        assertEquals("Ana", waiter?.name)
    }

    @Test
    fun abrir_com_armazenamento_corrompido_vai_para_o_login() = runTest {
        val waiter = auth(FakeApi(onMe = { WaiterEnvelope(waiterDto) }), FakeSession(failOnRead = true)).me()

        assertNull(waiter)
    }
}
