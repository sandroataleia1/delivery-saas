package br.com.esis.garcom.data

import kotlinx.coroutines.test.runTest
import kotlinx.serialization.SerializationException
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.ResponseBody.Companion.toResponseBody
import retrofit2.HttpException
import retrofit2.Response

/** Respostas que não são JSON (Wi-Fi com portal, proxy) e falhas do armazenamento não podem fechar o app. */
class RepositoryFailureTest {
    private val waiterDto = WaiterDto(7, "Ana", CompanyDto(1, "Isa Lanches"))

    private class FakeApi(
        val onLogin: () -> LoginResponse = { error("não usado") },
        val onMe: () -> WaiterEnvelope = { error("não usado") },
        val onTables: () -> TablesEnvelope = { error("não usado") },
        val onOrder: () -> OrderEnvelope = { error("não usado") },
        val onNotices: () -> NoticesEnvelope = { error("não usado") },
    ) : ApiService {
        override suspend fun login(body: LoginRequest): LoginResponse = onLogin()
        override suspend fun me(): WaiterEnvelope = onMe()
        override suspend fun logout(): Response<Unit> = Response.success(Unit)
        override suspend fun tables(): TablesEnvelope = onTables()
        override suspend fun menu(): MenuDto = error("não usado")
        override suspend fun order(id: Long): OrderEnvelope = onOrder()
        override suspend fun openTable(tableId: Long, body: OpenTableRequest): OrderEnvelope = error("não usado")
        override suspend fun addItems(orderId: Long, body: AddItemsRequest): OrderEnvelope = error("não usado")
        override suspend fun quote(orderId: Long, body: ItemRequest): QuoteDto = error("não usado")
        override suspend fun requestBill(orderId: Long, body: RequestBillRequest): OrderEnvelope = onOrder()
        override suspend fun transfer(orderId: Long, body: TransferRequest): OrderEnvelope = onOrder()
        override suspend fun deliver(orderId: Long, body: DeliverRequest): OrderEnvelope = onOrder()
        override suspend fun preBill(orderId: Long): Response<Unit> = Response.success(Unit)
        override suspend fun confirmReceipt(receiptId: Long): OrderEnvelope = onOrder()
        override suspend fun rejectReceipt(receiptId: Long, body: RejectReceiptRequest): OrderEnvelope = onOrder()
        override suspend fun notices(): NoticesEnvelope = onNotices()
        override suspend fun readNotices(body: ReadNoticesRequest): UnreadDto = error("não usado")
        override suspend fun app(): AppInfoDto = error("não usado")
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

    private fun httpError(code: Int, body: String) =
        HttpException(Response.error<Unit>(code, body.toResponseBody("application/json".toMediaType())))

    @Test
    fun rejeicao_422_traz_a_mensagem_do_servidor() = runTest {
        val repository = ApiOrderRepository(FakeApi(onOrder = { throw httpError(422, """{"message":"X está esgotado."}""") }))

        assertEquals(ApiResult.Rejected("X está esgotado."), repository.order(1))
    }

    @Test
    fun comanda_de_outra_empresa_vira_nao_encontrada() = runTest {
        val repository = ApiOrderRepository(FakeApi(onOrder = { throw httpError(404, "{}") }))

        assertEquals(ApiResult.NotFound, repository.order(1))
    }

    @Test
    fun status_de_item_desconhecido_vira_none() {
        assertEquals(ItemStatus.NONE, ItemStatus.fromApi("algo_novo"))
        assertEquals(ItemStatus.READY, ItemStatus.fromApi("ready"))
    }

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

    @Test
    fun avisos_desconhecidos_viram_other() = runTest {
        val json = """{"unread":1,"data":[
            {"id":9,"kind":"menu_order","title":"Mesa 4: pedido pelo QR","body":"2 itens","table_id":3,"order_id":12,"read":false,"created_at":"2026-11-11T12:00:00-03:00"},
            {"id":8,"kind":"algo_novo","title":"Outro","body":null,"table_id":null,"order_id":null,"read":true,"created_at":"2026-11-11T11:00:00-03:00"}
        ]}"""
        val repository = ApiNoticeRepository(FakeApi(onNotices = { ApiClient.json.decodeFromString(NoticesEnvelope.serializer(), json) }))

        val notices = (repository.notices() as ApiResult.Ok).value

        assertEquals(1, notices.unread)
        assertEquals(listOf(NoticeKind.MENU_ORDER, NoticeKind.OTHER), notices.items.map { it.kind })
        assertEquals(12L, notices.items[0].orderId)
        assertNull(notices.items[1].tableId)
    }

    @Test
    fun comanda_le_pedido_do_qr_e_itens_entregaveis() = runTest {
        val json = """{"data":{"id":12,"table":4,"people":2,"waiter":"Ana","minutes":5,"closing":true,"total":"20.00",
            "items":[{"id":1,"product_name":"X-Burger","options_label":null,"quantity":"1.000","unit":"UN","total":"20.00","notes":null,"status":"ready","deliverable":true}],
            "pending_receipts":[{"id":5,"total":"30.00","created_at":"2026-11-11T12:00:00-03:00","items":[{"product_name":"Fritas","quantity":2,"options_label":"Grande","notes":"sem sal"}]}]}}"""
        val repository = ApiOrderRepository(FakeApi(onOrder = { ApiClient.json.decodeFromString(OrderEnvelope.serializer(), json) }))

        val order = (repository.order(12) as ApiResult.Ok).value

        assertTrue(order.closing)
        assertTrue(order.items.single().deliverable)
        assertEquals(PendingReceipt(5, "30.00", listOf(ReceiptLine("Fritas", 2, "Grande", "sem sal"))), order.pendingReceipts.single())
    }

    @Test
    fun acao_sem_permissao_mostra_aviso_em_portugues() = runTest {
        val repository = ApiOrderRepository(FakeApi(onOrder = { throw httpError(403, """{"message":"This action is unauthorized."}""") }))

        assertEquals(ApiResult.Rejected("Seu acesso não permite esta ação. Fale com o gerente."), repository.deliver(12, listOf(1)))
    }
}
