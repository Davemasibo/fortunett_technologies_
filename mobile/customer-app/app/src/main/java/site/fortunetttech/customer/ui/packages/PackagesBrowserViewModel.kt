package site.fortunetttech.customer.ui.packages

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import site.fortunetttech.customer.data.model.AvailablePackage
import site.fortunetttech.customer.data.repository.PackagesRepository
import site.fortunetttech.customer.util.Result
import javax.inject.Inject

@HiltViewModel
class PackagesBrowserViewModel @Inject constructor(
    private val repo: PackagesRepository
) : ViewModel() {

    private val _all = MutableStateFlow<List<AvailablePackage>>(emptyList())
    val packages: StateFlow<List<AvailablePackage>> = _all.asStateFlow()

    private val _loading = MutableStateFlow(false)
    val loading: StateFlow<Boolean> = _loading.asStateFlow()

    private val _error = MutableStateFlow<String?>(null)
    val error: StateFlow<String?> = _error.asStateFlow()

    private var filterType: String? = null

    init { load() }

    fun load() {
        viewModelScope.launch {
            _loading.value = true
            _error.value   = null
            when (val r = repo.getPackages()) {
                is Result.Success -> _all.value = r.data.data
                is Result.Error   -> _error.value = r.message
            }
            _loading.value = false
        }
    }

    fun filtered(): List<AvailablePackage> = when (filterType) {
        "pppoe"   -> _all.value.filter { it.connection_type.lowercase() == "pppoe" }
        "hotspot" -> _all.value.filter { it.connection_type.lowercase() == "hotspot" }
        else      -> _all.value
    }

    fun setFilter(type: String?) { filterType = type }
}
