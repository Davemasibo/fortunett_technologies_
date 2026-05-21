package site.fortunetttech.admin.ui.routers

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import site.fortunetttech.admin.data.model.RoutersResponse
import site.fortunetttech.admin.data.repository.RouterRepository
import site.fortunetttech.admin.util.Result
import javax.inject.Inject

@HiltViewModel
class RoutersViewModel @Inject constructor(private val repo: RouterRepository) : ViewModel() {

    private val _routers = MutableStateFlow<Result<RoutersResponse>?>(null)
    val routers: StateFlow<Result<RoutersResponse>?> = _routers.asStateFlow()

    init { load() }

    fun load() {
        viewModelScope.launch { _routers.value = repo.getRouters() }
    }
}
