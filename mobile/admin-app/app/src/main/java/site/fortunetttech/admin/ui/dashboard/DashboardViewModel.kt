package site.fortunetttech.admin.ui.dashboard

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import site.fortunetttech.admin.data.model.DashboardStats
import site.fortunetttech.admin.data.repository.DashboardRepository
import site.fortunetttech.admin.util.Result
import javax.inject.Inject

@HiltViewModel
class DashboardViewModel @Inject constructor(private val repo: DashboardRepository) : ViewModel() {

    private val _stats = MutableStateFlow<Result<DashboardStats>?>(null)
    val stats: StateFlow<Result<DashboardStats>?> = _stats.asStateFlow()

    init { load() }

    fun load() {
        viewModelScope.launch { _stats.value = repo.getStats() }
    }
}
