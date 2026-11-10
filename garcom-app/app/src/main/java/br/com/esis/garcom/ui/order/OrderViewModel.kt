package br.com.esis.garcom.ui.order

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import br.com.esis.garcom.data.ApiResult
import br.com.esis.garcom.data.OrderRepository
import br.com.esis.garcom.data.TableOrder
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

sealed interface OrderState {
    data class Loading(val error: String? = null) : OrderState

    /** Mesa livre: pergunta quantas pessoas antes de abrir. */
    data class AskPeople(val opening: Boolean = false, val error: String? = null) : OrderState
    data class Showing(val order: TableOrder, val error: String?) : OrderState

    /** A comanda não está mais disponível (fechada, de outro garçom ao mesmo tempo): volta ao salão com o aviso. */
    data class Closed(val message: String) : OrderState
}

class OrderViewModel(
    private val repository: OrderRepository,
    private val tableId: Long,
    orderId: Long?,
) : ViewModel() {
    private var currentOrderId: Long? = orderId

    private val mutableState = MutableStateFlow<OrderState>(if (orderId == null) OrderState.AskPeople() else OrderState.Loading())
    val state: StateFlow<OrderState> = mutableState.asStateFlow()

    /** Comanda aberta agora, para o cardápio saber onde lançar. */
    val orderId: Long? get() = currentOrderId

    init {
        if (orderId != null) {
            viewModelScope.launch { refresh() }
        }
    }

    fun open(people: Int) {
        val current = mutableState.value
        if (current !is OrderState.AskPeople || current.opening) {
            return
        }
        mutableState.value = OrderState.AskPeople(opening = true)
        viewModelScope.launch {
            mutableState.value = when (val result = repository.open(tableId, people)) {
                is ApiResult.Ok -> {
                    currentOrderId = result.value.id
                    OrderState.Showing(result.value, error = null)
                }
                is ApiResult.Rejected -> OrderState.Closed(result.message)
                ApiResult.NotFound -> OrderState.Closed("Esta mesa não está mais disponível.")
                ApiResult.NoConnection -> OrderState.AskPeople(error = NO_CONNECTION)
                ApiResult.ServerError -> OrderState.AskPeople(error = SERVER_ERROR)
            }
        }
    }

    /** Recarrega a comanda. Se falhar, a última versão continua na tela com o aviso. */
    suspend fun refresh() {
        val id = currentOrderId ?: return
        val result = repository.order(id)
        val current = mutableState.value
        mutableState.value = when (result) {
            is ApiResult.Ok -> OrderState.Showing(result.value, error = null)
            is ApiResult.Rejected -> OrderState.Closed(result.message)
            ApiResult.NotFound -> OrderState.Closed("Esta comanda não está mais aberta.")
            ApiResult.NoConnection -> failed(current, NO_CONNECTION)
            ApiResult.ServerError -> failed(current, SERVER_ERROR)
        }
    }

    fun retry() {
        viewModelScope.launch { refresh() }
    }

    private fun failed(current: OrderState, message: String): OrderState = when (current) {
        is OrderState.Showing -> current.copy(error = message)
        is OrderState.Closed -> current
        else -> OrderState.Loading(error = message)
    }

    private companion object {
        const val NO_CONNECTION = "Sem conexão com o servidor"
        const val SERVER_ERROR = "O servidor está com problema. Tente de novo em instantes."
    }
}
