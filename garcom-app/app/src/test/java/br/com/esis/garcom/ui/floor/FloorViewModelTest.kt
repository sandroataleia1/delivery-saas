package br.com.esis.garcom.ui.floor

import br.com.esis.garcom.data.AuthRepository
import br.com.esis.garcom.data.FloorTable
import br.com.esis.garcom.data.LoginResult
import br.com.esis.garcom.data.TableRepository
import br.com.esis.garcom.data.TableStatus
import br.com.esis.garcom.data.TablesResult
import br.com.esis.garcom.data.Waiter
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class FloorViewModelTest {
    private val table = FloorTable(id = 1, number = 5, area = null, status = TableStatus.OPEN, waiter = "Ana", total = "42.00", minutes = 12)

    private class FakeTables(vararg results: TablesResult) : TableRepository {
        private val queue = ArrayDeque(results.toList())

        override suspend fun tables(): TablesResult = queue.removeFirst()
    }

    private class FakeAuth : AuthRepository {
        var loggedOut = false

        override suspend fun login(email: String, password: String): LoginResult = LoginResult.NoConnection

        override suspend fun me(): Waiter? = null

        override suspend fun logout() {
            loggedOut = true
        }
    }

    @Before
    fun setUp() {
        Dispatchers.setMain(UnconfinedTestDispatcher())
    }

    @After
    fun tearDown() {
        Dispatchers.resetMain()
    }

    @Test
    fun status_desconhecido_vira_ocupada() {
        assertEquals(TableStatus.OPEN, TableStatus.fromApi("xyz"))
        assertEquals(TableStatus.PENDING, TableStatus.fromApi("pending"))
    }

    @Test
    fun erro_de_rede_mantem_a_ultima_lista_e_mostra_aviso() = runTest {
        val viewModel = FloorViewModel(FakeTables(TablesResult.Loaded(listOf(table)), TablesResult.NoConnection), FakeAuth())

        viewModel.refresh()
        viewModel.refresh()

        val state = viewModel.state.value as FloorState.Showing
        assertEquals(listOf(table), state.tables)
        assertEquals("Sem conexão com o servidor", state.error)
    }

    @Test
    fun trocar_garcom_limpa_a_sessao() {
        val auth = FakeAuth()
        val viewModel = FloorViewModel(FakeTables(), auth)

        viewModel.switchWaiter()

        assertTrue(auth.loggedOut)
        assertEquals(FloorState.LoggedOut, viewModel.state.value)
    }
}
