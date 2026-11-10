package br.com.esis.garcom.ui.order

import br.com.esis.garcom.data.ApiResult
import br.com.esis.garcom.data.CartLine
import br.com.esis.garcom.data.ItemStatus
import br.com.esis.garcom.data.OrderItem
import br.com.esis.garcom.data.OrderRepository
import br.com.esis.garcom.data.Quote
import br.com.esis.garcom.data.TableOrder
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class OrderViewModelTest {
    private val order = TableOrder(
        id = 10, table = 5, people = 3, waiter = "Ana", minutes = 12, total = "56.00",
        items = listOf(OrderItem(1, "X-Burger", null, "2.000", "56.00", null, ItemStatus.WAITING)),
    )

    private class FakeOrders(
        val orders: ArrayDeque<ApiResult<TableOrder>> = ArrayDeque(),
        val opens: ArrayDeque<ApiResult<TableOrder>> = ArrayDeque(),
    ) : OrderRepository {
        var openedWith: Int? = null

        override suspend fun order(id: Long): ApiResult<TableOrder> = orders.removeFirst()
        override suspend fun open(tableId: Long, people: Int): ApiResult<TableOrder> {
            openedWith = people
            return opens.removeFirst()
        }
        override suspend fun addItems(orderId: Long, clientUuid: String, items: List<CartLine>): ApiResult<TableOrder> = error("não usado")
        override suspend fun quote(orderId: Long, line: CartLine): ApiResult<Quote> = error("não usado")
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
    fun mesa_livre_pede_pessoas_e_abre() = runTest {
        val orders = FakeOrders(opens = ArrayDeque(listOf(ApiResult.Ok(order))))
        val viewModel = OrderViewModel(orders, tableId = 1, orderId = null)

        assertEquals(OrderState.AskPeople(), viewModel.state.value)

        viewModel.open(3)

        assertEquals(3, orders.openedWith)
        assertEquals(OrderState.Showing(order, error = null), viewModel.state.value)
    }

    @Test
    fun mesa_ja_ocupada_por_outro_volta_ao_salao_com_aviso() = runTest {
        val orders = FakeOrders(opens = ArrayDeque(listOf(ApiResult.Rejected("A mesa 5 já está ocupada."))))
        val viewModel = OrderViewModel(orders, tableId = 1, orderId = null)

        viewModel.open(2)

        assertEquals(OrderState.Closed("A mesa 5 já está ocupada."), viewModel.state.value)
    }

    @Test
    fun abrir_sem_conexao_continua_pedindo_pessoas_com_aviso() = runTest {
        val orders = FakeOrders(opens = ArrayDeque(listOf(ApiResult.NoConnection)))
        val viewModel = OrderViewModel(orders, tableId = 1, orderId = null)

        viewModel.open(2)

        assertEquals(OrderState.AskPeople(error = "Sem conexão com o servidor"), viewModel.state.value)
    }

    @Test
    fun comanda_fechada_volta_ao_salao() = runTest {
        val orders = FakeOrders(orders = ArrayDeque(listOf(ApiResult.Rejected("Esta comanda já foi fechada."))))
        val viewModel = OrderViewModel(orders, tableId = 1, orderId = 10)

        assertEquals(OrderState.Closed("Esta comanda já foi fechada."), viewModel.state.value)
    }

    @Test
    fun sem_conexao_mantem_a_comanda_e_mostra_aviso() = runTest {
        val orders = FakeOrders(orders = ArrayDeque(listOf(ApiResult.Ok(order), ApiResult.NoConnection)))
        val viewModel = OrderViewModel(orders, tableId = 1, orderId = 10)

        viewModel.refresh()

        assertEquals(OrderState.Showing(order, error = "Sem conexão com o servidor"), viewModel.state.value)
    }
}
