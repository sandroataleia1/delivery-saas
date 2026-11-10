package br.com.esis.garcom.ui.menu

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import br.com.esis.garcom.data.ApiResult
import br.com.esis.garcom.data.CartLine
import br.com.esis.garcom.data.MenuCategory
import br.com.esis.garcom.data.MenuProduct
import br.com.esis.garcom.data.MenuRepository
import br.com.esis.garcom.data.OrderRepository
import java.text.Normalizer
import java.util.UUID
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/** Folha de montagem aberta para um produto com tamanho, sabores ou adicionais. */
data class SheetUi(val product: MenuProduct, val quoting: Boolean = false, val error: String? = null)

data class MenuUi(
    val loading: Boolean = true,
    val products: List<MenuProduct> = emptyList(),
    val categories: List<MenuCategory> = emptyList(),
    val selectedCategory: Long? = null,
    val query: String = "",
    val cart: Cart = Cart(),
    val sending: Boolean = false,
    val error: String? = null,
    val sent: Boolean = false,
    val sheet: SheetUi? = null,
    /** A comanda foi fechada enquanto o garçom escolhia: volta ao salão com a mensagem. */
    val closed: String? = null,
) {
    val visibleProducts: List<MenuProduct>
        get() {
            val needle = query.normalizedForSearch()
            return products.filter { product ->
                (selectedCategory == null || product.categoryId == selectedCategory) &&
                    (needle.isEmpty() || product.name.normalizedForSearch().contains(needle))
            }
        }
}

/** "Pão" e "PAO" viram "pao": a busca não liga para acento nem maiúscula. */
internal fun String.normalizedForSearch(): String =
    Normalizer.normalize(trim(), Normalizer.Form.NFD).replace(Regex("\\p{Mn}+"), "").lowercase()

class MenuViewModel(
    private val menu: MenuRepository,
    private val orders: OrderRepository,
    private val orderId: Long,
    private val newUuid: () -> String = { UUID.randomUUID().toString() },
) : ViewModel() {
    private val mutableState = MutableStateFlow(MenuUi())
    val state: StateFlow<MenuUi> = mutableState.asStateFlow()

    /** Identifica o envio em andamento. Mantido em toda nova tentativa para o servidor não lançar duas vezes. */
    private var pendingUuid: String? = null

    init {
        load()
    }

    fun load() {
        mutableState.update { it.copy(loading = true, error = null) }
        viewModelScope.launch {
            when (val result = menu.menu()) {
                is ApiResult.Ok -> mutableState.update {
                    it.copy(loading = false, products = result.value.products, categories = result.value.categories)
                }
                is ApiResult.Rejected -> mutableState.update { it.copy(loading = false, error = result.message) }
                ApiResult.NotFound, ApiResult.ServerError -> mutableState.update { it.copy(loading = false, error = SERVER_ERROR) }
                ApiResult.NoConnection -> mutableState.update { it.copy(loading = false, error = "Sem conexão com o servidor") }
            }
        }
    }

    fun selectCategory(id: Long?) = mutableState.update { it.copy(selectedCategory = id) }

    fun search(query: String) = mutableState.update { it.copy(query = query) }

    /** Botão + da lista: produto simples entra direto; produto montado abre a folha. */
    fun tapAdd(product: MenuProduct) {
        if (!editable()) {
            return
        }
        if (product.composition == null) {
            mutableState.update { it.copy(cart = it.cart.add(CartLine.simple(product.id, product.name, product.price)), error = null) }
        } else {
            mutableState.update { it.copy(sheet = SheetUi(product)) }
        }
    }

    /** Botão − da lista: tira uma unidade da última montagem desse produto. */
    fun tapRemove(product: MenuProduct) {
        if (!editable()) {
            return
        }
        val key = state.value.cart.lines.lastOrNull { it.productId == product.id }?.key ?: return
        mutableState.update { it.copy(cart = it.cart.decrement(key)) }
    }

    fun closeSheet() {
        if (state.value.sheet?.quoting != true) {
            mutableState.update { it.copy(sheet = null) }
        }
    }

    /** Produto montado só entra no carrinho com o preço e o rótulo que o servidor calcular. */
    fun addComposed(line: CartLine) {
        val sheet = state.value.sheet ?: return
        if (sheet.quoting) {
            return
        }
        mutableState.update { it.copy(sheet = sheet.copy(quoting = true, error = null)) }
        viewModelScope.launch {
            when (val result = orders.quote(orderId, line)) {
                is ApiResult.Ok -> mutableState.update {
                    val priced = line.copy(unitPrice = result.value.unitPrice, optionsLabel = result.value.optionsLabel)
                    it.copy(cart = it.cart.add(priced), sheet = null, error = null)
                }
                is ApiResult.Rejected -> if (result.message == ORDER_CLOSED) {
                    mutableState.update { it.copy(sheet = null, closed = result.message) }
                } else {
                    sheetFailed(result.message)
                }
                ApiResult.NotFound -> mutableState.update { it.copy(sheet = null, closed = ORDER_CLOSED) }
                ApiResult.NoConnection -> sheetFailed("Sem conexão com o servidor")
                ApiResult.ServerError -> sheetFailed(SERVER_ERROR)
            }
        }
    }

    fun send() {
        val current = state.value
        if (current.sending || current.cart.lines.isEmpty()) {
            return
        }
        val uuid = pendingUuid ?: newUuid().also { pendingUuid = it }
        mutableState.update { it.copy(sending = true, error = null) }
        viewModelScope.launch {
            when (val result = orders.addItems(orderId, uuid, current.cart.lines)) {
                is ApiResult.Ok -> {
                    pendingUuid = null
                    mutableState.update { it.copy(sending = false, cart = Cart(), sent = true) }
                }
                is ApiResult.Rejected -> if (result.message == ORDER_CLOSED) {
                    mutableState.update { it.copy(sending = false, closed = result.message) }
                } else {
                    // Recusado: nada foi lançado. O garçom ajusta o carrinho e o próximo envio é um pedido novo.
                    pendingUuid = null
                    mutableState.update { it.copy(sending = false, error = result.message) }
                }
                ApiResult.NotFound -> mutableState.update { it.copy(sending = false, closed = ORDER_CLOSED) }
                ApiResult.NoConnection -> mutableState.update {
                    it.copy(sending = false, error = "Sem conexão. Toque em Enviar de novo — o pedido não será duplicado.")
                }
                ApiResult.ServerError -> mutableState.update { it.copy(sending = false, error = SERVER_ERROR) }
            }
        }
    }

    /** A tela já voltou à comanda: a próxima visita ao cardápio não deve voltar sozinha. */
    fun acknowledgeSent() = mutableState.update { it.copy(sent = false) }

    /** O garçom desistiu dos itens. Um envio pendente também é esquecido: o próximo carrinho é um pedido novo. */
    fun discardCart() {
        if (state.value.sending) {
            return
        }
        pendingUuid = null
        mutableState.update { it.copy(cart = Cart(), error = null) }
    }

    /**
     * Com um envio pendente (falha de rede), o servidor pode já ter lançado o carrinho. Mudar o carrinho e reenviar com o
     * mesmo uuid faria o servidor devolver o pedido antigo sem lançar o que foi acrescentado; por isso o carrinho fica
     * travado até reenviar ou descartar.
     */
    private fun editable(): Boolean {
        if (pendingUuid == null) {
            return true
        }
        mutableState.update { it.copy(error = PENDING_SEND) }
        return false
    }

    private fun sheetFailed(message: String) = mutableState.update { state ->
        state.copy(sheet = state.sheet?.copy(quoting = false, error = message))
    }

    private companion object {
        const val PENDING_SEND = "Envie de novo ou descarte antes de mudar o pedido."
        const val ORDER_CLOSED = "Esta comanda já foi fechada."
        const val SERVER_ERROR = "O servidor está com problema. Tente de novo em instantes."
    }
}
