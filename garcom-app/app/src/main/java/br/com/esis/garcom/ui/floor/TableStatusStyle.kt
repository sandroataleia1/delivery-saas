package br.com.esis.garcom.ui.floor

import androidx.annotation.DrawableRes
import androidx.compose.ui.graphics.Color
import br.com.esis.garcom.R
import br.com.esis.garcom.data.TableStatus

/**
 * Cor, texto e ícone de cada status: o texto e o ícone sempre acompanham a cor, para não depender só dela.
 * "Pedido da mesa" é o único card de cor cheia, para o garçom ver de longe.
 */
data class TableStatusStyle(
    val label: String,
    @param:DrawableRes val icon: Int?,
    val container: Color,
    val border: Color,
    val content: Color,
)

fun TableStatus.style(dark: Boolean): TableStatusStyle = when (this) {
    TableStatus.FREE -> if (dark) {
        TableStatusStyle("Livre", null, Color(0xFF1C1917), Color(0xFF3A3532), Color(0xFFE7E5E4))
    } else {
        TableStatusStyle("Livre", null, Color.White, Color(0xFFE7E5E4), Color(0xFF44403C))
    }
    TableStatus.OPEN -> if (dark) {
        TableStatusStyle("Ocupada", R.drawable.ic_clock, Color(0xFF431407), Color(0xFF9A3412), Color(0xFFFED7AA))
    } else {
        TableStatusStyle("Ocupada", R.drawable.ic_clock, Color(0xFFFFF7ED), Color(0xFFFDBA74), Color(0xFF7C2D12))
    }
    TableStatus.PENDING -> if (dark) {
        TableStatusStyle("Pedido da mesa", R.drawable.ic_bell, Color(0xFFDC2626), Color(0xFFDC2626), Color.White)
    } else {
        TableStatusStyle("Pedido da mesa", R.drawable.ic_bell, Color(0xFFB91C1C), Color(0xFFB91C1C), Color.White)
    }
    TableStatus.CLOSING -> if (dark) {
        TableStatusStyle("Conta pedida", R.drawable.ic_receipt, Color(0xFF172554), Color(0xFF1D4ED8), Color(0xFFBFDBFE))
    } else {
        TableStatusStyle("Conta pedida", R.drawable.ic_receipt, Color(0xFFEFF6FF), Color(0xFF93C5FD), Color(0xFF1E3A8A))
    }
}
