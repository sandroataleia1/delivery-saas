package br.com.esis.garcom.ui

import android.content.Context
import android.os.Build
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.lifecycle.viewmodel.compose.viewModel
import br.com.esis.garcom.data.ApiAuthRepository
import br.com.esis.garcom.data.ApiClient
import br.com.esis.garcom.data.ApiMenuRepository
import br.com.esis.garcom.data.ApiOrderRepository
import br.com.esis.garcom.data.ApiService
import br.com.esis.garcom.data.ApiTableRepository
import br.com.esis.garcom.data.AuthRepository
import br.com.esis.garcom.data.KeystoreSessionStore
import br.com.esis.garcom.data.MenuRepository
import br.com.esis.garcom.data.OrderRepository
import br.com.esis.garcom.data.SessionEvents
import br.com.esis.garcom.data.TableRepository
import br.com.esis.garcom.data.Waiter
import br.com.esis.garcom.ui.floor.FloorScreen
import br.com.esis.garcom.ui.floor.FloorViewModel
import br.com.esis.garcom.ui.login.LoginScreen
import br.com.esis.garcom.ui.login.LoginViewModel

/** Dependências do app, criadas uma vez por processo. */
class AppGraph private constructor(context: Context) {
    val session = KeystoreSessionStore(context)
    val api: ApiService = ApiClient.service(session)
    val auth: AuthRepository = ApiAuthRepository(api, session, session, "${Build.MANUFACTURER} ${Build.MODEL}")
    val tables: TableRepository = ApiTableRepository(api)
    val orders: OrderRepository = ApiOrderRepository(api)
    val menu: MenuRepository = ApiMenuRepository(api)

    companion object {
        @Volatile
        private var instance: AppGraph? = null

        fun get(context: Context): AppGraph = instance ?: synchronized(this) {
            instance ?: AppGraph(context.applicationContext).also { instance = it }
        }
    }
}

private sealed interface Screen {
    data object Starting : Screen
    data object Login : Screen
    data class Floor(val waiter: Waiter) : Screen
}

@Composable
fun AppNavigation(graph: AppGraph) {
    var screen by remember { mutableStateOf<Screen>(Screen.Starting) }

    /** Muda a cada saída: cada login ganha ViewModels novos, sem herdar o estado do garçom anterior. */
    var session by rememberSaveable { mutableIntStateOf(0) }
    val backToLogin = {
        session++
        screen = Screen.Login
    }

    LaunchedEffect(Unit) {
        screen = graph.auth.me()?.let { Screen.Floor(it) } ?: Screen.Login
    }
    LaunchedEffect(Unit) {
        SessionEvents.expired.collect { backToLogin() }
    }

    when (val current = screen) {
        Screen.Starting -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            CircularProgressIndicator()
        }
        Screen.Login -> LoginScreen(
            viewModel = viewModel(key = "login-$session") { LoginViewModel(graph.auth) },
            onLoggedIn = { screen = Screen.Floor(it) },
        )
        is Screen.Floor -> FloorScreen(
            viewModel = viewModel(key = "floor-$session") { FloorViewModel(graph.tables, graph.auth) },
            waiter = current.waiter,
            onLoggedOut = backToLogin,
        )
    }
}
