package br.com.esis.garcom.ui.notices

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import br.com.esis.garcom.data.ApiResult
import br.com.esis.garcom.data.Notice
import br.com.esis.garcom.data.NoticeRepository
import java.time.Duration
import java.time.OffsetDateTime
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class NoticesState(val items: List<Notice>, val loading: Boolean, val error: String?)

/** Lista de avisos do garçom. Ao mostrar avisos novos, marca como lidos até o maior id exibido. */
class NoticesViewModel(private val repository: NoticeRepository) : ViewModel() {
    private val mutableState = MutableStateFlow(NoticesState(emptyList(), loading = true, error = null))
    val state: StateFlow<NoticesState> = mutableState.asStateFlow()

    init {
        viewModelScope.launch { refresh() }
    }

    /** Recarrega os avisos. Se falhar, a última lista continua na tela com o aviso. */
    suspend fun refresh() {
        when (val result = repository.notices()) {
            is ApiResult.Ok -> {
                mutableState.value = NoticesState(result.value.items, loading = false, error = null)
                if (result.value.items.any { !it.read }) {
                    repository.markRead(result.value.items.maxOf { it.id })
                }
            }
            ApiResult.NoConnection -> failed("Sem conexão com o servidor")
            else -> failed("O servidor está com problema. Tente de novo em instantes.")
        }
    }

    fun retry() {
        viewModelScope.launch { refresh() }
    }

    private fun failed(message: String) {
        mutableState.value = mutableState.value.copy(loading = false, error = message)
    }
}

/** "agora", "5 min", "2 h" ou "3 d". Data que não dá para ler fica em branco. */
fun relativeTime(createdAt: String, now: OffsetDateTime = OffsetDateTime.now()): String {
    val moment = runCatching { OffsetDateTime.parse(createdAt) }.getOrNull() ?: return ""
    val minutes = Duration.between(moment, now).toMinutes()
    return when {
        minutes < 1 -> "agora"
        minutes < 60 -> "$minutes min"
        minutes < 24 * 60 -> "${minutes / 60} h"
        else -> "${minutes / (24 * 60)} d"
    }
}
