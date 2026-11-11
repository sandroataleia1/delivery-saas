package br.com.esis.garcom.ui.floor

import br.com.esis.garcom.data.FloorTable
import br.com.esis.garcom.data.TableStatus
import java.math.BigDecimal
import java.text.DecimalFormat
import java.text.DecimalFormatSymbols
import java.util.Locale

/** Filtros do salão. "Atenção" junta o que pede o garçom agora: pedido da mesa e conta pedida. */
enum class TableFilter(val label: String) {
    ALL("Todas"),
    ATTENTION("Atenção"),
    OPEN("Ocupadas"),
    FREE("Livres"),
    ;

    fun matches(status: TableStatus): Boolean = when (this) {
        ALL -> true
        ATTENTION -> status == TableStatus.PENDING || status == TableStatus.CLOSING
        OPEN -> status == TableStatus.OPEN
        FREE -> status == TableStatus.FREE
    }
}

fun List<FloorTable>.filteredBy(filter: TableFilter): List<FloorTable> = filter { filter.matches(it.status) }

/** Linha curta do card: o que o garçom precisa saber da mesa sem abrir a comanda. */
fun tableInfo(table: FloorTable): String = when (table.status) {
    TableStatus.FREE -> plural(table.seats, "lugar", "lugares")
    TableStatus.PENDING -> plural(table.pendingCount, "item novo", "itens novos")
    TableStatus.CLOSING -> table.total?.let(::money).orEmpty()
    TableStatus.OPEN -> listOfNotNull(table.minutes?.let { "$it min" }, table.total?.let(::money)).joinToString(" · ")
}

private fun plural(count: Int, one: String, many: String): String = when {
    count <= 0 -> ""
    count == 1 -> "1 $one"
    else -> "$count $many"
}

private val moneyFormat = DecimalFormat("#,##0.00", DecimalFormatSymbols(Locale.forLanguageTag("pt-BR")))

/** "86.50" da API vira "R$ 86,50". Valor que não é número fica de fora. */
fun money(value: String): String? = value.toBigDecimalOrNull()?.let { "R$ " + moneyFormat.format(it as BigDecimal) }
