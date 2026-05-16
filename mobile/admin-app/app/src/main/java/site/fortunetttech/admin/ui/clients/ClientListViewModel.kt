package site.fortunetttech.admin.ui.clients

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import site.fortunetttech.admin.data.model.ClientsResponse
import site.fortunetttech.admin.data.repository.ClientRepository
import site.fortunetttech.admin.util.Result
import javax.inject.Inject

@HiltViewModel
class ClientListViewModel @Inject constructor(private val repo: ClientRepository) : ViewModel() {

    private val _clients = MutableStateFlow<Result<ClientsResponse>?>(null)
    val clients: StateFlow<Result<ClientsResponse>?> = _clients.asStateFlow()

    private var currentPage  = 1
    private var currentSearch: String? = null
    private var isLastPage   = false

    init { load() }

    fun load(search: String? = null) {
        currentSearch = search
        currentPage   = 1
        isLastPage    = false
        viewModelScope.launch {
            val r = repo.getClients(page = 1, search = search)
            _clients.value = r
            if (r is Result.Success) {
                isLastPage = r.data.pagination.page >= r.data.pagination.last_page
            }
        }
    }

    fun loadNextPage() {
        if (isLastPage) return
        viewModelScope.launch {
            val next   = currentPage + 1
            val result = repo.getClients(page = next, search = currentSearch)
            if (result is Result.Success) {
                currentPage = next
                isLastPage  = result.data.pagination.page >= result.data.pagination.last_page
                val existing = (_clients.value as? Result.Success)?.data?.data ?: emptyList()
                _clients.value = Result.Success(result.data.copy(data = existing + result.data.data))
            }
        }
    }
}
