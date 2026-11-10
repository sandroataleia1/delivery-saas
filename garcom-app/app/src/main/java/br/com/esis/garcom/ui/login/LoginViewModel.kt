package br.com.esis.garcom.ui.login

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import br.com.esis.garcom.data.AuthRepository
import br.com.esis.garcom.data.LoginResult
import br.com.esis.garcom.data.Waiter
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

sealed interface LoginState {
    data object Idle : LoginState
    data object Loading : LoginState
    data class Error(val message: String) : LoginState
    data class LoggedIn(val waiter: Waiter) : LoginState
}

class LoginViewModel(private val repository: AuthRepository) : ViewModel() {
    private val mutableState = MutableStateFlow<LoginState>(LoginState.Idle)
    val state: StateFlow<LoginState> = mutableState.asStateFlow()

    fun login(email: String, password: String) {
        if (email.isBlank() || password.isBlank()) {
            mutableState.value = LoginState.Error("Informe e-mail e senha")
            return
        }
        if (mutableState.value == LoginState.Loading) {
            return
        }

        mutableState.value = LoginState.Loading
        viewModelScope.launch {
            mutableState.value = when (val result = repository.login(email.trim(), password)) {
                is LoginResult.Success -> LoginState.LoggedIn(result.waiter)
                is LoginResult.InvalidCredentials -> LoginState.Error(result.message)
                is LoginResult.NotAWaiter -> LoginState.Error(result.message)
                LoginResult.NoConnection -> LoginState.Error("Sem conexão com o servidor")
            }
        }
    }
}
