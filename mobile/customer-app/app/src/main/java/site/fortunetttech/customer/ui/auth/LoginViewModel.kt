package site.fortunetttech.customer.ui.auth

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import site.fortunetttech.customer.data.repository.AuthRepository
import site.fortunetttech.customer.util.Result
import javax.inject.Inject

@HiltViewModel
class LoginViewModel @Inject constructor(private val repo: AuthRepository) : ViewModel() {

    sealed class UiState {
        object Idle    : UiState()
        object Loading : UiState()
        object Success : UiState()
        data class Error(val message: String) : UiState()
    }

    private val _state = MutableStateFlow<UiState>(UiState.Idle)
    val state: StateFlow<UiState> = _state.asStateFlow()

    fun login(subdomain: String, username: String, password: String) {
        if (subdomain.isBlank() || username.isBlank() || password.isBlank()) {
            _state.value = UiState.Error("All fields are required")
            return
        }
        viewModelScope.launch {
            _state.value = UiState.Loading
            _state.value = when (val r = repo.login(subdomain.trim(), username.trim(), password)) {
                is Result.Success -> UiState.Success
                is Result.Error   -> UiState.Error(r.message)
            }
        }
    }
}
