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
    /** [busy]: uma ação está em andamento e as outras esperam. [message]: confirmação curta de uma ação que deu certo. */
    data class Showing(val order: TableOrder, val error: String?, val busy: Boolean = false, val message: String? = null) : OrderState

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
            is ApiResult.Ok -> (current as? OrderState.Showing)?.copy(order = result.value, error = null)
                ?: OrderState.Showing(result.value, error = null)
            is ApiResult.Rejected -> OrderState.Closed(result.message)
            ApiResult.NotFound -> OrderState.Closed("Esta comanda não está mais aberta.")
            ApiResult.NoConnection -> failed(current, NO_CONNECTION)
            ApiResult.ServerError -> failed(current, SERVER_ERROR)
        }
    }

    fun retry() {
        viewModelScope.launch { refresh() }
    }

    /** Registra que os itens prontos foram levados à mesa. */
    fun deliver(itemIds: List<Long>) {
        if (itemIds.isEmpty()) {
            return
        }
        act { order -> repository.deliver(order.id, itemIds) }
    }

    fun deliverAllReady() {
        val showing = mutableState.value as? OrderState.Showing ?: return
        deliver(showing.order.items.filter { it.deliverable }.map { it.id })
    }

    /** Avisa o caixa que a mesa pediu a conta, ou desfaz o aviso. */
    fun toggleBill() {
        val showing = mutableState.value as? OrderState.Showing ?: return
        val requested = !showing.order.closing
        act(if (requested) "Caixa avisado: a mesa pediu a conta." else null) { order -> repository.requestBill(order.id, requested) }
    }

    fun printBill() {
        val showing = mutableState.value as? OrderState.Showing ?: return
        if (showing.busy) {
            return
        }
        mutableState.value = showing.copy(busy = true, error = null, message = null)
        viewModelScope.launch {
            mutableState.value = when (val result = repository.printBill(showing.order.id)) {
                is ApiResult.Ok -> current(showing).copy(busy = false, message = "Conta enviada para a impressora.")
                else -> settle(showing, result)
            }
        }
    }

    /** Passa a comanda para outra mesa livre. O garçom continua na mesma comanda, agora com o número novo. */
    fun transfer(tableId: Long) {
        act { order -> repository.transfer(order.id, tableId) }
    }

    fun confirmReceipt(receiptId: Long) {
        act("Pedido do cliente confirmado e enviado para a produção.") { repository.confirmReceipt(receiptId) }
    }

    /** O motivo é obrigatório: o cliente vê por que o pedido foi recusado. */
    fun rejectReceipt(receiptId: Long, reason: String) {
        val trimmed = reason.trim()
        if (trimmed.isEmpty()) {
            return
        }
        act("Pedido do cliente recusado.") { repository.rejectReceipt(receiptId, trimmed.take(255)) }
    }

    fun dismissMessage() {
        val showing = mutableState.value as? OrderState.Showing ?: return
        mutableState.value = showing.copy(message = null)
    }

    /** Uma ação por vez: enquanto uma está em andamento, toques repetidos são ignorados. */
    private fun act(successMessage: String? = null, call: suspend (TableOrder) -> ApiResult<TableOrder>) {
        val showing = mutableState.value as? OrderState.Showing ?: return
        if (showing.busy) {
            return
        }
        mutableState.value = showing.copy(busy = true, error = null, message = null)
        viewModelScope.launch {
            mutableState.value = when (val result = call(showing.order)) {
                is ApiResult.Ok -> OrderState.Showing(result.value, error = null, message = successMessage)
                else -> settle(showing, result)
            }
        }
    }

    /** Falha de uma ação: a comanda fechada volta ao salão; o resto mantém a comanda na tela com o aviso. */
    private fun settle(before: OrderState.Showing, result: ApiResult<*>): OrderState {
        val showing = current(before).copy(busy = false)
        return when (result) {
            is ApiResult.Rejected ->
                if (result.message == CLOSED) OrderState.Closed(result.message) else showing.copy(error = result.message)
            ApiResult.NotFound -> OrderState.Closed("Esta comanda não está mais aberta.")
            ApiResult.NoConnection -> showing.copy(error = NO_CONNECTION)
            ApiResult.ServerError -> showing.copy(error = SERVER_ERROR)
            is ApiResult.Ok -> showing
        }
    }

    /** A comanda pode ter sido recarregada enquanto a ação rodava; usa a versão mais nova. */
    private fun current(fallback: OrderState.Showing): OrderState.Showing = mutableState.value as? OrderState.Showing ?: fallback

    private fun failed(current: OrderState, message: String): OrderState = when (current) {
        is OrderState.Showing -> current.copy(error = message)
        is OrderState.Closed -> current
        else -> OrderState.Loading(error = message)
    }

    private companion object {
        const val NO_CONNECTION = "Sem conexão com o servidor"
        const val SERVER_ERROR = "O servidor está com problema. Tente de novo em instantes."
        const val CLOSED = "Esta comanda já foi fechada."
    }
}
