package br.com.esis.garcom.ui.order

import androidx.compose.ui.graphics.Color
import br.com.esis.garcom.data.ItemStatus
import java.math.BigDecimal

/** Selo do item na comanda: texto e cor juntos, para não depender só da cor. */
data class ItemStatusStyle(val label: String, val container: Color, val content: Color)

fun ItemStatus.style(): ItemStatusStyle? = when (this) {
    ItemStatus.WAITING, ItemStatus.PREPARING -> ItemStatusStyle("Na cozinha", Color(0xFFFEF3C7), Color(0xFF92400E))
    ItemStatus.READY -> ItemStatusStyle("Pronto para levar", Color(0xFFDCFCE7), Color(0xFF166534))
    ItemStatus.DELIVERED -> ItemStatusStyle("Entregue", Color(0xFFF5F5F4), Color(0xFF57534E))
    ItemStatus.CANCELLED -> ItemStatusStyle("Cancelado", Color(0xFFF5F5F4), Color(0xFF78716C))
    ItemStatus.NONE -> null
}

/** "2.000" → "2"; "1.500" → "1,5". */
fun quantityLabel(quantity: String): String =
    runCatching { BigDecimal(quantity).stripTrailingZeros().toPlainString().replace('.', ',') }.getOrDefault(quantity)
