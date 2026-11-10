package br.com.esis.garcom.ui.floor

import androidx.compose.ui.graphics.Color
import br.com.esis.garcom.data.TableStatus

/** Cor e texto de cada status: o texto sempre acompanha a cor, para não depender só dela. */
data class TableStatusStyle(val label: String, val container: Color, val content: Color)

fun TableStatus.style(): TableStatusStyle = when (this) {
    TableStatus.FREE -> TableStatusStyle("Livre", Color(0xFFE7F6EC), Color(0xFF14532D))
    TableStatus.OPEN -> TableStatusStyle("Ocupada", Color(0xFFFFEDD5), Color(0xFF7C2D12))
    TableStatus.PENDING -> TableStatusStyle("Pedido da mesa", Color(0xFFFEE2E2), Color(0xFF991B1B))
    TableStatus.CLOSING -> TableStatusStyle("Conta pedida", Color(0xFFDBEAFE), Color(0xFF1E3A8A))
}
