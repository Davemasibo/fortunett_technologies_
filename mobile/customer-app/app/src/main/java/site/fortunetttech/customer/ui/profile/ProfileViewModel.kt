package site.fortunetttech.customer.ui.profile

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import site.fortunetttech.customer.data.model.*
import site.fortunetttech.customer.data.repository.ProfileRepository
import site.fortunetttech.customer.util.Result
import javax.inject.Inject

@HiltViewModel
class ProfileViewModel @Inject constructor(private val repo: ProfileRepository) : ViewModel() {

    private val _profile = MutableStateFlow<Result<ProfileResponse>?>(null)
    val profile: StateFlow<Result<ProfileResponse>?> = _profile.asStateFlow()

    private val _message = MutableSharedFlow<String>()
    val message: SharedFlow<String> = _message.asSharedFlow()

    private val _loading = MutableStateFlow(false)
    val loading: StateFlow<Boolean> = _loading.asStateFlow()

    private val _sessions = MutableStateFlow<List<Session>>(emptyList())
    val sessions: StateFlow<List<Session>> = _sessions.asStateFlow()

    init { load() }

    fun load() {
        viewModelScope.launch {
            _loading.value = true
            _profile.value = repo.getProfile()
            _loading.value = false
        }
        viewModelScope.launch {
            when (val r = repo.getSessions()) {
                is Result.Success -> _sessions.value = r.data
                is Result.Error   -> { /* silent — sessions are non-critical */ }
            }
        }
    }

    fun updateProfile(fullName: String, phone: String, email: String) {
        viewModelScope.launch {
            _loading.value = true
            val req = UpdateProfileRequest(
                full_name = fullName.ifBlank { null },
                phone     = phone.ifBlank { null },
                email     = email.ifBlank { null }
            )
            when (val r = repo.updateProfile(req)) {
                is Result.Success -> { _message.emit(r.data); load() }
                is Result.Error   -> _message.emit("Error: ${r.message}")
            }
            _loading.value = false
        }
    }

    fun changePassword(current: String, new: String) {
        viewModelScope.launch {
            _loading.value = true
            when (val r = repo.changePassword(ChangePasswordRequest(current, new))) {
                is Result.Success -> _message.emit(r.data)
                is Result.Error   -> _message.emit("Error: ${r.message}")
            }
            _loading.value = false
        }
    }
}
