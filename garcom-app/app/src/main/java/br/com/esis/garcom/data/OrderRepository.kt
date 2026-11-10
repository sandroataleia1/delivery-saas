package br.com.esis.garcom.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

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
)

data class TableOrder(
    val id: Long,
    val table: Int,
    val people: Int,
    val waiter: String?,
    val minutes: Int,
    val total: String,
    val items: List<OrderItem>,
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
}

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
) {
    fun toOrder(): TableOrder = TableOrder(id, table, people, waiter, minutes, total, items.map { it.toItem() })
}

@Serializable
data class OrderItemDto(
    val id: Long,
    @SerialName("product_name") val productName: String,
    @SerialName("options_label") val optionsLabel: String? = null,
    val quantity: String,
    val total: String,
    val notes: String? = null,
    val status: String,
) {
    fun toItem(): OrderItem = OrderItem(id, productName, optionsLabel, quantity, total, notes, ItemStatus.fromApi(status))
}
