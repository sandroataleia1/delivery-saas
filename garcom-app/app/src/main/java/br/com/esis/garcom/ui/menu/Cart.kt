package br.com.esis.garcom.ui.menu

import br.com.esis.garcom.data.CartLine
import java.math.BigDecimal
import java.math.RoundingMode

/** Carrinho do pedido que ainda não foi enviado. Imutável: cada mudança devolve um carrinho novo. */
data class Cart(val lines: List<CartLine> = emptyList()) {
    val count: Int get() = lines.sumOf { it.quantity }

    /** Soma de preço × quantidade, com 2 casas, só para mostrar. O servidor recalcula ao lançar. */
    val total: String
        get() = lines.fold(BigDecimal.ZERO) { sum, line -> sum + BigDecimal(line.unitPrice) * BigDecimal(line.quantity) }
            .setScale(2, RoundingMode.HALF_UP)
            .toPlainString()

    fun quantityOf(productId: Long): Int = lines.filter { it.productId == productId }.sumOf { it.quantity }

    fun add(line: CartLine): Cart = if (lines.any { it.key == line.key }) {
        Cart(lines.map { if (it.key == line.key) it.copy(quantity = it.quantity + line.quantity) else it })
    } else {
        Cart(lines + line)
    }

    fun increment(key: String): Cart = Cart(lines.map { if (it.key == key) it.copy(quantity = it.quantity + 1) else it })

    fun decrement(key: String): Cart = Cart(
        lines.mapNotNull { if (it.key != key) it else if (it.quantity > 1) it.copy(quantity = it.quantity - 1) else null },
    )
}
