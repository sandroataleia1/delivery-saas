package br.com.esis.garcom.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import retrofit2.HttpException

/** Situação do item na produção, como o garçom precisa saber. */
enum class ItemStatus {
    WAITING,
    PREPARING,
    READY,
    DELIVERED,
    CANCELLED,
    NONE,
    ;

    companion object {
        /** Situação que o app não conhece (servidor mais novo) fica sem selo, sem derrubar o app. */
        fun fromApi(value: String): ItemStatus = when (value) {
            "waiting" -> WAITING
            "preparing" -> PREPARING
            "ready" -> READY
            "delivered" -> DELIVERED
            "cancelled" -> CANCELLED
            else -> NONE
        }
    }
}

data class OrderItem(
    val id: Long,
    val name: String,
    val optionsLabel: String?,
    val quantity: String,
    val total: String,
    val notes: String?,
    val status: ItemStatus,
    /** Pronto na produção e ainda não levado à mesa. */
    val deliverable: Boolean = false,
)

/** Linha de um pedido feito pelo cliente no QR, esperando o garçom confirmar. */
data class ReceiptLine(val name: String, val quantity: Int, val optionsLabel: String?, val notes: String?)

data class PendingReceipt(val id: Long, val total: String, val items: List<ReceiptLine>)

data class TableOrder(
    val id: Long,
    val table: Int,
    val people: Int,
    val waiter: String?,
    val minutes: Int,
    val total: String,
    val items: List<OrderItem>,
    /** A mesa pediu a conta e o caixa já foi avisado. */
    val closing: Boolean = false,
    val pendingReceipts: List<PendingReceipt> = emptyList(),
)

data class Quote(val unitPrice: String, val optionsLabel: String?)

data class ChosenOption(val optionId: Long, val quantity: Int = 1)

/** Linha do carrinho. Produto simples usa a chave "p<id>"; cada montagem (pizza, adicionais) tem chave própria. */
data class CartLine(
    val key: String,
    val productId: Long,
    val name: String,
    val quantity: Int,
    val unitPrice: String,
    val optionsLabel: String? = null,
    val notes: String? = null,
    val sizeId: Long? = null,
    val flavorIds: List<Long> = emptyList(),
    val options: List<ChosenOption> = emptyList(),
) {
    fun toRequest(): ItemRequest = ItemRequest(
        productId = productId,
        quantity = quantity,
        notes = notes,
        sizeId = sizeId,
        flavorIds = flavorIds.ifEmpty { null },
        options = options.map { OptionRequest(it.optionId, it.quantity) }.ifEmpty { null },
    )

    companion object {
        fun simple(productId: Long, name: String, price: String): CartLine =
            CartLine(key = "p$productId", productId = productId, name = name, quantity = 1, unitPrice = price)
    }
}

interface OrderRepository {
    suspend fun order(id: Long): ApiResult<TableOrder>
    suspend fun open(tableId: Long, people: Int): ApiResult<TableOrder>
    suspend fun addItems(orderId: Long, clientUuid: String, items: List<CartLine>): ApiResult<TableOrder>
    suspend fun quote(orderId: Long, line: CartLine): ApiResult<Quote>
    suspend fun requestBill(orderId: Long, requested: Boolean): ApiResult<TableOrder>
    suspend fun transfer(orderId: Long, tableId: Long): ApiResult<TableOrder>
    suspend fun deliver(orderId: Long, itemIds: List<Long>): ApiResult<TableOrder>
    suspend fun confirmReceipt(receiptId: Long): ApiResult<TableOrder>
    suspend fun rejectReceipt(receiptId: Long, reason: String): ApiResult<TableOrder>

    /** Pede a impressão da pré-conta. A mesa continua aberta. */
    suspend fun printBill(orderId: Long): ApiResult<Unit>
}

class ApiOrderRepository(private val api: ApiService) : OrderRepository {
    override suspend fun order(id: Long): ApiResult<TableOrder> = apiCall { api.order(id).data.toOrder() }

    override suspend fun open(tableId: Long, people: Int): ApiResult<TableOrder> =
        apiCall { api.openTable(tableId, OpenTableRequest(people)).data.toOrder() }

    override suspend fun addItems(orderId: Long, clientUuid: String, items: List<CartLine>): ApiResult<TableOrder> = apiCall {
        api.addItems(orderId, AddItemsRequest(clientUuid, items.map { it.toRequest() })).data.toOrder()
    }

    override suspend fun quote(orderId: Long, line: CartLine): ApiResult<Quote> = apiCall {
        api.quote(orderId, line.toRequest()).let { Quote(it.unitPrice, it.optionsLabel) }
    }

    override suspend fun requestBill(orderId: Long, requested: Boolean): ApiResult<TableOrder> =
        apiCall { api.requestBill(orderId, RequestBillRequest(requested)).data.toOrder() }

    override suspend fun transfer(orderId: Long, tableId: Long): ApiResult<TableOrder> =
        apiCall { api.transfer(orderId, TransferRequest(tableId)).data.toOrder() }

    override suspend fun deliver(orderId: Long, itemIds: List<Long>): ApiResult<TableOrder> =
        apiCall { api.deliver(orderId, DeliverRequest(itemIds)).data.toOrder() }

    override suspend fun confirmReceipt(receiptId: Long): ApiResult<TableOrder> =
        apiCall { api.confirmReceipt(receiptId).data.toOrder() }

    override suspend fun rejectReceipt(receiptId: Long, reason: String): ApiResult<TableOrder> =
        apiCall { api.rejectReceipt(receiptId, RejectReceiptRequest(reason)).data.toOrder() }

    override suspend fun printBill(orderId: Long): ApiResult<Unit> = apiCall {
        val response = api.preBill(orderId)
        if (!response.isSuccessful) {
            throw HttpException(response)
        }
    }
}

@Serializable
data class RequestBillRequest(val requested: Boolean)

@Serializable
data class TransferRequest(@SerialName("dining_table_id") val diningTableId: Long)

@Serializable
data class DeliverRequest(@SerialName("item_ids") val itemIds: List<Long>)

@Serializable
data class RejectReceiptRequest(val reason: String)

@Serializable
data class OpenTableRequest(val people: Int)

@Serializable
data class OptionRequest(@SerialName("option_id") val optionId: Long, val quantity: Int)

@Serializable
data class ItemRequest(
    @SerialName("product_id") val productId: Long,
    val quantity: Int,
    val notes: String? = null,
    @SerialName("size_id") val sizeId: Long? = null,
    @SerialName("flavor_ids") val flavorIds: List<Long>? = null,
    val options: List<OptionRequest>? = null,
)

@Serializable
data class AddItemsRequest(@SerialName("client_uuid") val clientUuid: String, val items: List<ItemRequest>)

@Serializable
data class QuoteDto(@SerialName("unit_price") val unitPrice: String, @SerialName("options_label") val optionsLabel: String? = null)

@Serializable
data class OrderEnvelope(val data: OrderDto)

@Serializable
data class OrderDto(
    val id: Long,
    val table: Int,
    val people: Int,
    val waiter: String? = null,
    val minutes: Int = 0,
    val total: String,
    val items: List<OrderItemDto> = emptyList(),
    val closing: Boolean = false,
    @SerialName("pending_receipts") val pendingReceipts: List<PendingReceiptDto> = emptyList(),
) {
    fun toOrder(): TableOrder = TableOrder(
        id, table, people, waiter, minutes, total, items.map { it.toItem() },
        closing = closing,
        pendingReceipts = pendingReceipts.map { it.toReceipt() },
    )
}

@Serializable
data class PendingReceiptDto(val id: Long, val total: String, val items: List<ReceiptLineDto> = emptyList()) {
    fun toReceipt(): PendingReceipt =
        PendingReceipt(id, total, items.map { ReceiptLine(it.productName, it.quantity, it.optionsLabel, it.notes) })
}

@Serializable
data class ReceiptLineDto(
    @SerialName("product_name") val productName: String,
    val quantity: Int,
    @SerialName("options_label") val optionsLabel: String? = null,
    val notes: String? = null,
)

@Serializable
data class OrderItemDto(
    val id: Long,
    @SerialName("product_name") val productName: String,
    @SerialName("options_label") val optionsLabel: String? = null,
    val quantity: String,
    val total: String,
    val notes: String? = null,
    val status: String,
    val deliverable: Boolean = false,
) {
    fun toItem(): OrderItem = OrderItem(id, productName, optionsLabel, quantity, total, notes, ItemStatus.fromApi(status), deliverable)
}
