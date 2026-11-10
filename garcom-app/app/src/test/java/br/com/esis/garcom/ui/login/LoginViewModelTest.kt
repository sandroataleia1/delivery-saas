package br.com.esis.garcom.ui.login

import br.com.esis.garcom.data.AuthRepository
import br.com.esis.garcom.data.LoginResult
import br.com.esis.garcom.data.Waiter
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class LoginViewModelTest {
    private val waiter = Waiter(id = 7, name = "Ana", companyName = "Isa Lanches", logoUrl = null)

    private class FakeAuthRepository(private val result: LoginResult) : AuthRepository {
        var calls = 0

        override suspend fun login(email: String, password: String): LoginResult {
            calls++
            return result
        }

        override suspend fun me(): Waiter? = null

        override suspend fun logout() {}
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
    fun login_ok_vai_para_o_salao() {
        val viewModel = LoginViewModel(FakeAuthRepository(LoginResult.Success(waiter)))

        viewModel.login("ana@isa.com", "segredo")

        assertEquals(LoginState.LoggedIn(waiter), viewModel.state.value)
    }

    @Test
    fun credencial_errada_mostra_a_mensagem_do_servidor() {
        val message = "Essas credenciais não correspondem aos nossos registros."
        val viewModel = LoginViewModel(FakeAuthRepository(LoginResult.InvalidCredentials(message)))

        viewModel.login("ana@isa.com", "errada")

        assertEquals(LoginState.Error(message), viewModel.state.value)
    }

    @Test
    fun sem_conexao_mostra_aviso_e_permite_tentar_de_novo() {
        val repository = FakeAuthRepository(LoginResult.NoConnection)
        val viewModel = LoginViewModel(repository)

        viewModel.login("ana@isa.com", "segredo")
        assertEquals(LoginState.Error("Sem conexão com o servidor"), viewModel.state.value)

        viewModel.login("ana@isa.com", "segredo")
        assertEquals(2, repository.calls)
    }

    @Test
    fun campos_vazios_nao_chamam_o_servidor() {
        val repository = FakeAuthRepository(LoginResult.Success(waiter))
        val viewModel = LoginViewModel(repository)

        viewModel.login(" ", "")

        assertEquals(0, repository.calls)
        assertEquals(LoginState.Error("Informe e-mail e senha"), viewModel.state.value)
    }
}
