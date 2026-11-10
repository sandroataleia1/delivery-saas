package br.com.esis.garcom.ui

import android.content.Context
import android.os.Build
import android.widget.Toast
import androidx.activity.compose.BackHandler
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
import androidx.compose.ui.platform.LocalContext
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
import br.com.esis.garcom.ui.menu.MenuScreen
import br.com.esis.garcom.ui.menu.MenuViewModel
import br.com.esis.garcom.ui.order.OrderScreen
import br.com.esis.garcom.ui.order.OrderViewModel

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

    /** Comanda da mesa. Sem orderId, a mesa está livre e a tela pergunta quantas pessoas antes de abrir. */
    data class Order(val waiter: Waiter, val tableId: Long, val tableNumber: Int, val orderId: Long?) : Screen

    /** Cardápio para lançar itens numa comanda já aberta. */
    data class Menu(val waiter: Waiter, val tableId: Long, val tableNumber: Int, val orderId: Long) : Screen
}

@Composable
fun AppNavigation(graph: AppGraph) {
    val context = LocalContext.current
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
            onOpenTable = { table -> screen = Screen.Order(current.waiter, table.id, table.number, table.orderId) },
        )
        is Screen.Order -> {
            val toFloor = { screen = Screen.Floor(current.waiter) }
            BackHandler(onBack = toFloor)
            OrderScreen(
                viewModel = viewModel(key = "order-$session-${current.tableId}-${current.orderId}") {
                    OrderViewModel(graph.orders, current.tableId, current.orderId)
                },
                tableNumber = current.tableNumber,
                onBack = toFloor,
                onClosed = { message ->
                    Toast.makeText(context, message, Toast.LENGTH_LONG).show()
                    toFloor()
                },
                onAddItems = { orderId -> screen = Screen.Menu(current.waiter, current.tableId, current.tableNumber, orderId) },
            )
        }
        is Screen.Menu -> {
            val toOrder = { screen = Screen.Order(current.waiter, current.tableId, current.tableNumber, current.orderId) }
            MenuScreen(
                viewModel = viewModel(key = "menu-$session-${current.orderId}") {
                    MenuViewModel(graph.menu, graph.orders, current.orderId)
                },
                tableNumber = current.tableNumber,
                onBack = toOrder,
                onSent = {
                    Toast.makeText(context, "Pedido enviado para a cozinha", Toast.LENGTH_SHORT).show()
                    toOrder()
                },
                onClosed = { message ->
                    Toast.makeText(context, message, Toast.LENGTH_LONG).show()
                    screen = Screen.Floor(current.waiter)
                },
            )
        }
    }
}
