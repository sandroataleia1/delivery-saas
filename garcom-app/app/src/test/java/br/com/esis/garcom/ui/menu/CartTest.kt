package br.com.esis.garcom.ui.menu

import br.com.esis.garcom.data.CartLine
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class CartTest {
    private fun simple(id: Long, price: String = "28.00") = CartLine.simple(id, "Produto $id", price)

    private fun composed(key: String, id: Long = 9, price: String = "42.00") = CartLine(
        key = key, productId = id, name = "Pizza", quantity = 1, unitPrice = price,
        optionsLabel = "Grande · Calabresa", notes = null, sizeId = 3, flavorIds = listOf(5), options = emptyList(),
    )

    @Test
    fun produto_simples_soma_na_mesma_linha() {
        val cart = Cart().add(simple(1)).add(simple(1))

        assertEquals(1, cart.lines.size)
        assertEquals(2, cart.lines.single().quantity)
        assertEquals(2, cart.count)
        assertEquals(2, cart.quantityOf(1))
    }

    @Test
    fun total_soma_preco_vezes_quantidade() {
        val cart = Cart().add(simple(1)).add(simple(1)).add(simple(2, "18.00"))

        assertEquals("74.00", cart.total)
    }

    @Test
    fun diminuir_ate_zero_tira_a_linha() {
        val cart = Cart().add(simple(1)).increment("p1").decrement("p1").decrement("p1")

        assertTrue(cart.lines.isEmpty())
        assertEquals("0.00", cart.total)
    }

    @Test
    fun montagens_diferentes_do_mesmo_produto_ficam_separadas() {
        val cart = Cart().add(composed("m1")).add(composed("m2", price = "50.00"))

        assertEquals(2, cart.lines.size)
        assertEquals(2, cart.quantityOf(9))
        assertEquals("92.00", cart.total)
    }
}
