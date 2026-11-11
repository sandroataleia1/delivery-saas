package br.com.esis.garcom.ui.menu

import br.com.esis.garcom.data.ApiResult
import br.com.esis.garcom.data.CartLine
import br.com.esis.garcom.data.Composition
import br.com.esis.garcom.data.Menu
import br.com.esis.garcom.data.MenuCategory
import br.com.esis.garcom.data.MenuProduct
import br.com.esis.garcom.data.MenuRepository
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
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class MenuViewModelTest {
    private val burger = MenuProduct(1, "X-Bacon", categoryId = 1, price = "28.00", fromPrice = "28.00", composition = null)
    private val pao = MenuProduct(2, "Pão de queijo", categoryId = 2, price = "8.50", fromPrice = "8.50", composition = null)
    private val pizza = MenuProduct(3, "Pizza", categoryId = 3, price = "0.00", fromPrice = "35.00", composition = Composition(null, emptyList()))
    private val menu = Menu(
        categories = listOf(MenuCategory(1, "Lanches"), MenuCategory(2, "Salgados"), MenuCategory(3, "Pizzas")),
        products = listOf(burger, pao, pizza),
    )
    private val order = TableOrder(10, 5, 2, "Ana", 3, "28.00", emptyList())

    private class FakeMenu(val menu: Menu) : MenuRepository {
        override suspend fun menu(): ApiResult<Menu> = ApiResult.Ok(menu)
    }

    private class FakeOrders(
        val sends: ArrayDeque<ApiResult<TableOrder>> = ArrayDeque(),
        val quotes: ArrayDeque<ApiResult<Quote>> = ArrayDeque(),
    ) : OrderRepository {
        val sentUuids = mutableListOf<String>()

        override suspend fun order(id: Long): ApiResult<TableOrder> = error("não usado")
        override suspend fun open(tableId: Long, people: Int): ApiResult<TableOrder> = error("não usado")
        override suspend fun addItems(orderId: Long, clientUuid: String, items: List<CartLine>): ApiResult<TableOrder> {
            sentUuids += clientUuid
            return sends.removeFirst()
        }
        override suspend fun quote(orderId: Long, line: CartLine): ApiResult<Quote> = quotes.removeFirst()
        override suspend fun requestBill(orderId: Long, requested: Boolean): ApiResult<TableOrder> = error("não usado")
        override suspend fun transfer(orderId: Long, tableId: Long): ApiResult<TableOrder> = error("não usado")
        override suspend fun deliver(orderId: Long, itemIds: List<Long>): ApiResult<TableOrder> = error("não usado")
        override suspend fun confirmReceipt(receiptId: Long): ApiResult<TableOrder> = error("não usado")
        override suspend fun rejectReceipt(receiptId: Long, reason: String): ApiResult<TableOrder> = error("não usado")
        override suspend fun printBill(orderId: Long): ApiResult<Unit> = error("não usado")
    }

    private fun viewModel(orders: FakeOrders): MenuViewModel {
        var next = 0
        return MenuViewModel(FakeMenu(menu), orders, orderId = 10, newUuid = { "uuid-${++next}" })
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
    fun envio_ok_limpa_o_carrinho_e_volta() = runTest {
        val viewModel = viewModel(FakeOrders(sends = ArrayDeque(listOf(ApiResult.Ok(order)))))
        viewModel.tapAdd(burger)

        viewModel.send()

        assertTrue(viewModel.state.value.sent)
        assertEquals(0, viewModel.state.value.cart.count)
    }

    @Test
    fun falha_de_rede_mantem_carrinho_e_reenvia_com_o_mesmo_uuid() = runTest {
        val orders = FakeOrders(sends = ArrayDeque(listOf(ApiResult.NoConnection, ApiResult.Ok(order))))
        val viewModel = viewModel(orders)
        viewModel.tapAdd(burger)

        viewModel.send()

        assertEquals(1, viewModel.state.value.cart.count)
        assertEquals("Sem conexão. Toque em Enviar de novo — o pedido não será duplicado.", viewModel.state.value.error)

        viewModel.send()

        assertEquals(listOf("uuid-1", "uuid-1"), orders.sentUuids)
        assertTrue(viewModel.state.value.sent)
    }

    @Test
    fun depois_de_enviar_o_proximo_pedido_usa_uuid_novo() = runTest {
        val orders = FakeOrders(sends = ArrayDeque(listOf(ApiResult.Ok(order), ApiResult.Ok(order))))
        val viewModel = viewModel(orders)

        viewModel.tapAdd(burger)
        viewModel.send()
        viewModel.tapAdd(pao)
        viewModel.send()

        assertEquals(listOf("uuid-1", "uuid-2"), orders.sentUuids)
    }

    @Test
    fun esgotado_mostra_a_mensagem_e_mantem_o_carrinho() = runTest {
        val viewModel = viewModel(FakeOrders(sends = ArrayDeque(listOf(ApiResult.Rejected("X-Bacon está esgotado.")))))
        viewModel.tapAdd(burger)

        viewModel.send()

        assertEquals("X-Bacon está esgotado.", viewModel.state.value.error)
        assertEquals(1, viewModel.state.value.cart.count)
        assertFalse(viewModel.state.value.sent)
    }

    @Test
    fun busca_ignora_acento_e_maiusculas() = runTest {
        val viewModel = viewModel(FakeOrders())

        viewModel.search("PAO")

        assertEquals(listOf(pao), viewModel.state.value.visibleProducts)
    }

    @Test
    fun produto_montado_so_entra_com_a_cotacao_do_servidor() = runTest {
        val orders = FakeOrders(
            quotes = ArrayDeque(listOf(ApiResult.Rejected("Escolha um tamanho."), ApiResult.Ok(Quote("42.00", "Grande · Calabresa")))),
        )
        val viewModel = viewModel(orders)
        val line = CartLine("c3-s2", productId = 3, name = "Pizza", quantity = 1, unitPrice = "0.00", sizeId = 2, flavorIds = listOf(7))

        viewModel.tapAdd(pizza)
        assertEquals(pizza, viewModel.state.value.sheet?.product)

        viewModel.addComposed(line)

        assertEquals(0, viewModel.state.value.cart.count)
        assertEquals("Escolha um tamanho.", viewModel.state.value.sheet?.error)

        viewModel.addComposed(line)

        assertNull(viewModel.state.value.sheet)
        assertEquals("42.00", viewModel.state.value.cart.lines.single().unitPrice)
        assertEquals("Grande · Calabresa", viewModel.state.value.cart.lines.single().optionsLabel)
    }

    @Test
    fun voltar_ao_cardapio_depois_de_enviar_comeca_do_zero() = runTest {
        val viewModel = viewModel(FakeOrders(sends = ArrayDeque(listOf(ApiResult.Ok(order)))))
        viewModel.tapAdd(burger)
        viewModel.send()

        viewModel.acknowledgeSent()

        assertFalse(viewModel.state.value.sent)
    }

    @Test
    fun descartar_esvazia_o_carrinho() = runTest {
        val viewModel = viewModel(FakeOrders())
        viewModel.tapAdd(burger)

        viewModel.discardCart()

        assertEquals(0, viewModel.state.value.cart.count)
    }

    @Test
    fun com_envio_pendente_o_carrinho_nao_muda_ate_reenviar() = runTest {
        val orders = FakeOrders(sends = ArrayDeque(listOf(ApiResult.NoConnection)))
        val viewModel = viewModel(orders)
        viewModel.tapAdd(burger)
        viewModel.send()

        viewModel.tapAdd(pao)
        viewModel.tapRemove(burger)

        assertEquals(listOf(1L), viewModel.state.value.cart.lines.map { it.productId })
        assertEquals("Envie de novo ou descarte antes de mudar o pedido.", viewModel.state.value.error)
    }

    @Test
    fun nao_descarta_enquanto_envia() = runTest {
        val orders = object : OrderRepository by FakeOrders() {
            override suspend fun addItems(orderId: Long, clientUuid: String, items: List<CartLine>): ApiResult<TableOrder> =
                kotlinx.coroutines.awaitCancellation()
        }
        val viewModel = MenuViewModel(FakeMenu(menu), orders, orderId = 10)
        viewModel.tapAdd(burger)
        viewModel.send()

        viewModel.discardCart()

        assertTrue(viewModel.state.value.sending)
        assertEquals(1, viewModel.state.value.cart.count)
    }
}
