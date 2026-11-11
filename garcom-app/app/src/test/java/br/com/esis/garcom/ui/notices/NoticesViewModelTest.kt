package br.com.esis.garcom.ui.notices

import br.com.esis.garcom.data.ApiResult
import br.com.esis.garcom.data.Notice
import br.com.esis.garcom.data.NoticeKind
import br.com.esis.garcom.data.NoticeRepository
import br.com.esis.garcom.data.Notices
import java.time.OffsetDateTime
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class NoticesViewModelTest {
    private val ready = Notice(9, NoticeKind.READY, "Mesa 4: 1 item pronto — Cozinha", "1× X-Burger", 3, 12, read = false, createdAt = "2026-11-11T12:00:00-03:00")
    private val old = Notice(7, NoticeKind.MENU_ORDER, "Mesa 2: pedido pelo QR", null, 1, 8, read = true, createdAt = "2026-11-11T10:00:00-03:00")

    private class FakeNotices(vararg results: ApiResult<Notices>) : NoticeRepository {
        private val queue = ArrayDeque(results.toList())
        val markedUpTo = mutableListOf<Long>()

        override suspend fun notices(): ApiResult<Notices> = queue.removeFirst()
        override suspend fun markRead(upToId: Long): ApiResult<Int> {
            markedUpTo += upToId
            return ApiResult.Ok(0)
        }
    }

    @Before
    fun setUp() {
        Dispatchers.setMain(UnconfinedTestDispatcher())
    }

    @After
    fun tearDown() {
        Dispatchers.resetMain()
    }

    @Test
    fun abrir_avisos_marca_como_lidos_ate_o_maior_id() = runTest {
        val notices = FakeNotices(ApiResult.Ok(Notices(1, listOf(ready, old))))

        val viewModel = NoticesViewModel(notices)

        assertEquals(listOf(9L), notices.markedUpTo)
        assertEquals(NoticesState(listOf(ready, old), loading = false, error = null), viewModel.state.value)
    }

    @Test
    fun avisos_sem_conexao_mostram_aviso_e_mantem_lista() = runTest {
        val notices = FakeNotices(ApiResult.Ok(Notices(0, listOf(old))), ApiResult.NoConnection)
        val viewModel = NoticesViewModel(notices)

        viewModel.refresh()

        assertEquals(NoticesState(listOf(old), loading = false, error = "Sem conexão com o servidor"), viewModel.state.value)
        assertEquals(emptyList<Long>(), notices.markedUpTo)
    }

    @Test
    fun tempo_do_aviso_em_texto_curto() {
        val now = OffsetDateTime.parse("2026-11-11T12:00:00-03:00")

        assertEquals("agora", relativeTime("2026-11-11T11:59:30-03:00", now))
        assertEquals("5 min", relativeTime("2026-11-11T11:55:00-03:00", now))
        assertEquals("2 h", relativeTime("2026-11-11T10:00:00-03:00", now))
        assertEquals("", relativeTime("data inválida", now))
    }
}
