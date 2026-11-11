package br.com.esis.garcom.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** Tipo do aviso. Um tipo que o app não conhece (servidor mais novo) aparece como aviso comum. */
enum class NoticeKind {
    MENU_ORDER,
    READY,
    OTHER,
    ;

    companion object {
        fun fromApi(value: String): NoticeKind = when (value) {
            "menu_order" -> MENU_ORDER
            "ready" -> READY
            else -> OTHER
        }
    }
}

/** Aviso para o garçom: pedido do cliente pelo QR ou itens prontos na produção. */
data class Notice(
    val id: Long,
    val kind: NoticeKind,
    val title: String,
    val body: String?,
    val tableId: Long?,
    val orderId: Long?,
    val read: Boolean,
    val createdAt: String,
)

data class Notices(val unread: Int, val items: List<Notice>)

interface NoticeRepository {
    suspend fun notices(): ApiResult<Notices>

    /** Marca como lidos os avisos até [upToId]. Devolve quantos ainda não foram lidos. */
    suspend fun markRead(upToId: Long): ApiResult<Int>
}

class ApiNoticeRepository(private val api: ApiService) : NoticeRepository {
    override suspend fun notices(): ApiResult<Notices> = apiCall {
        api.notices().let { envelope -> Notices(envelope.unread, envelope.data.map { it.toNotice() }) }
    }

    override suspend fun markRead(upToId: Long): ApiResult<Int> = apiCall { api.readNotices(ReadNoticesRequest(upToId)).unread }
}

@Serializable
data class NoticesEnvelope(val unread: Int = 0, val data: List<NoticeDto> = emptyList())

@Serializable
data class NoticeDto(
    val id: Long,
    val kind: String,
    val title: String,
    val body: String? = null,
    @SerialName("table_id") val tableId: Long? = null,
    @SerialName("order_id") val orderId: Long? = null,
    val read: Boolean = false,
    @SerialName("created_at") val createdAt: String,
) {
    fun toNotice(): Notice = Notice(id, NoticeKind.fromApi(kind), title, body, tableId, orderId, read, createdAt)
}

@Serializable
data class ReadNoticesRequest(@SerialName("up_to_id") val upToId: Long)

@Serializable
data class UnreadDto(val unread: Int = 0)
