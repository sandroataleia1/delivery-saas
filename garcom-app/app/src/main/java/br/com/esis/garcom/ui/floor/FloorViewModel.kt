package br.com.esis.garcom.ui.floor

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import br.com.esis.garcom.data.ApiResult
import br.com.esis.garcom.data.AuthRepository
import br.com.esis.garcom.data.NoticeRepository
import br.com.esis.garcom.data.FloorTable
import br.com.esis.garcom.data.TableRepository
import br.com.esis.garcom.data.TablesResult
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

sealed interface FloorState {
    /** [unread]: avisos ainda não lidos, para o selo do sino. */
    data class Showing(val tables: List<FloorTable>, val loading: Boolean, val error: String?, val unread: Int = 0) : FloorState
    data object LoggedOut : FloorState
}

class FloorViewModel(
    private val repository: TableRepository,
    private val auth: AuthRepository,
    private val notices: NoticeRepository? = null,
) : ViewModel() {
    private val mutableState = MutableStateFlow<FloorState>(FloorState.Showing(emptyList(), loading = true, error = null))
    val state: StateFlow<FloorState> = mutableState.asStateFlow()

    /** Recarrega o salão. Se falhar, a última lista continua na tela com o aviso. */
    suspend fun refresh() {
        val result = repository.tables()
        // Sem os avisos, o selo fica com a última contagem conhecida; o salão não mostra erro por isso.
        val unread = (notices?.notices() as? ApiResult.Ok)?.value?.unread
        mutableState.update { current ->
            if (current !is FloorState.Showing) {
                return@update current
            }
            val counted = current.copy(unread = unread ?: current.unread)
            when (result) {
                is TablesResult.Loaded -> FloorState.Showing(result.tables, loading = false, error = null, unread = counted.unread)
                TablesResult.NoConnection -> counted.copy(loading = false, error = "Sem conexão com o servidor")
                TablesResult.ServerError -> counted.copy(loading = false, error = "O servidor está com problema. Tente de novo em instantes.")
            }
        }
    }

    fun retry() {
        viewModelScope.launch { refresh() }
    }

    fun switchWaiter() {
        viewModelScope.launch {
            auth.logout()
            mutableState.value = FloorState.LoggedOut
        }
    }
}
