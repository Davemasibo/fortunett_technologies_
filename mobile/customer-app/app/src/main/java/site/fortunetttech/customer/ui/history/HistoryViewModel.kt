package site.fortunetttech.customer.ui.history

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import site.fortunetttech.customer.data.model.ProfileResponse
import site.fortunetttech.customer.data.repository.ProfileRepository
import site.fortunetttech.customer.util.Result
import javax.inject.Inject

@HiltViewModel
class HistoryViewModel @Inject constructor(private val repo: ProfileRepository) : ViewModel() {

    private val _profile = MutableStateFlow<Result<ProfileResponse>?>(null)
    val profile: StateFlow<Result<ProfileResponse>?> = _profile.asStateFlow()

    init { load() }

    fun load() {
        viewModelScope.launch { _profile.value = repo.getProfile() }
    }
}
