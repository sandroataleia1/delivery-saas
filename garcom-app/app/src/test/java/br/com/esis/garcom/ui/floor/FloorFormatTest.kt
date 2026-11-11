package br.com.esis.garcom.ui.floor

import br.com.esis.garcom.data.FloorTable
import br.com.esis.garcom.data.TableStatus
import org.junit.Assert.assertEquals
import org.junit.Test

class FloorFormatTest {
    private fun table(
        number: Int,
        status: TableStatus,
        seats: Int = 4,
        pendingCount: Int = 0,
        total: String? = null,
        minutes: Int? = null,
    ) = FloorTable(
        id = number.toLong(),
        number = number,
        area = null,
        seats = seats,
        status = status,
        waiter = null,
        total = total,
        minutes = minutes,
        pendingCount = pendingCount,
    )

    @Test
    fun filtros_separam_atencao_ocupadas_e_livres() {
        val tables = listOf(
            table(1, TableStatus.FREE),
            table(2, TableStatus.OPEN),
            table(3, TableStatus.PENDING),
            table(4, TableStatus.CLOSING),
            table(5, TableStatus.OPEN),
        )

        assertEquals(listOf(1, 2, 3, 4, 5), tables.filteredBy(TableFilter.ALL).map { it.number })
        assertEquals(listOf(3, 4), tables.filteredBy(TableFilter.ATTENTION).map { it.number })
        assertEquals(listOf(2, 5), tables.filteredBy(TableFilter.OPEN).map { it.number })
        assertEquals(listOf(1), tables.filteredBy(TableFilter.FREE).map { it.number })
    }

    @Test
    fun ocupada_mostra_tempo_e_total_em_reais() {
        assertEquals("32 min · R$ 86,50", tableInfo(table(2, TableStatus.OPEN, total = "86.50", minutes = 32)))
        assertEquals("R$ 1.234,00", tableInfo(table(2, TableStatus.OPEN, total = "1234")))
    }

    @Test
    fun pedido_da_mesa_mostra_quantos_itens_novos() {
        assertEquals("1 item novo", tableInfo(table(3, TableStatus.PENDING, pendingCount = 1)))
        assertEquals("2 itens novos", tableInfo(table(3, TableStatus.PENDING, pendingCount = 2)))
    }

    @Test
    fun conta_pedida_mostra_o_total_e_livre_mostra_os_lugares() {
        assertEquals("R$ 214,90", tableInfo(table(6, TableStatus.CLOSING, total = "214.9", minutes = 50)))
        assertEquals("4 lugares", tableInfo(table(1, TableStatus.FREE, seats = 4)))
        assertEquals("1 lugar", tableInfo(table(1, TableStatus.FREE, seats = 1)))
        assertEquals("", tableInfo(table(1, TableStatus.FREE, seats = 0)))
    }
}
