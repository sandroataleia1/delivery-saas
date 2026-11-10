package br.com.esis.garcom.data

import kotlinx.coroutines.CancellationException
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import retrofit2.HttpException

enum class TableStatus {
    FREE,
    OPEN,
    PENDING,
    CLOSING,
    ;

    companion object {
        /** Status que o app não conhece (servidor mais novo) aparece como mesa ocupada, sem derrubar o app. */
        fun fromApi(value: String): TableStatus = when (value) {
            "free" -> FREE
            "pending" -> PENDING
            "closing" -> CLOSING
            else -> OPEN
        }
    }
}

data class FloorTable(
    val id: Long,
    val number: Int,
    val area: String?,
    val seats: Int = 0,
    val status: TableStatus,
    val waiter: String?,
    val total: String?,
    val minutes: Int?,
    val pendingCount: Int = 0,
)

sealed interface TablesResult {
    data class Loaded(val tables: List<FloorTable>) : TablesResult
    data object NoConnection : TablesResult
    data object ServerError : TablesResult
}

interface TableRepository {
    suspend fun tables(): TablesResult
}

@Serializable
data class TablesEnvelope(val data: List<TableDto>)

@Serializable
data class TableDto(
    val id: Long,
    val number: Int,
    val area: String? = null,
    val seats: Int = 0,
    val status: String,
    val order: TableOrderDto? = null,
) {
    fun toFloorTable(): FloorTable = FloorTable(
        id = id,
        number = number,
        area = area,
        seats = seats,
        status = TableStatus.fromApi(status),
        waiter = order?.waiter,
        total = order?.total,
        minutes = order?.minutes,
        pendingCount = order?.pendingCount ?: 0,
    )
}

@Serializable
data class TableOrderDto(
    val waiter: String? = null,
    val total: String? = null,
    val minutes: Int? = null,
    @SerialName("pending_count") val pendingCount: Int = 0,
)

class ApiTableRepository(private val api: ApiService) : TableRepository {
    override suspend fun tables(): TablesResult = try {
        TablesResult.Loaded(api.tables().data.map { it.toFloorTable() })
    } catch (error: CancellationException) {
        throw error
    } catch (error: HttpException) {
        TablesResult.ServerError
    } catch (error: Exception) {
        // Sem rede, ou uma resposta que não é da API (Wi-Fi com página de login, proxy).
        TablesResult.NoConnection
    }
}
