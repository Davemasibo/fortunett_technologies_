package site.fortunetttech.admin.ui.clients

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.appcompat.widget.SearchView
import androidx.fragment.app.Fragment
import androidx.fragment.app.viewModels
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch
import site.fortunetttech.admin.databinding.FragmentClientListBinding
import site.fortunetttech.admin.util.Result

@AndroidEntryPoint
class ClientListFragment : Fragment() {

    private var _binding: FragmentClientListBinding? = null
    private val binding get() = _binding!!
    private val vm: ClientListViewModel by viewModels()
    private val adapter = ClientAdapter { client ->
        val args = Bundle().apply { putInt("clientId", client.id) }
        findNavController().navigate(site.fortunetttech.admin.R.id.clientDetailFragment, args)
    }

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentClientListBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.recyclerView.adapter       = adapter
        binding.recyclerView.layoutManager = LinearLayoutManager(requireContext())
        binding.fabAdd.setOnClickListener { findNavController().navigate(site.fortunetttech.admin.R.id.addClientFragment) }

        binding.recyclerView.addOnScrollListener(object : RecyclerView.OnScrollListener() {
            override fun onScrolled(rv: RecyclerView, dx: Int, dy: Int) {
                val lm = rv.layoutManager as LinearLayoutManager
                if (lm.findLastVisibleItemPosition() >= adapter.itemCount - 5) vm.loadNextPage()
            }
        })

        binding.swipeRefresh.setOnRefreshListener {
            vm.load(binding.searchView.query?.toString()?.takeIf { it.isNotBlank() })
        }

        binding.searchView.setOnQueryTextListener(object : SearchView.OnQueryTextListener {
            override fun onQueryTextSubmit(query: String?): Boolean {
                vm.load(query?.takeIf { it.isNotBlank() }); return true
            }
            override fun onQueryTextChange(newText: String?) = false
        })

        lifecycleScope.launch {
            vm.clients.collectLatest { result ->
                binding.swipeRefresh.isRefreshing = false
                when (result) {
                    null -> {}
                    is Result.Success -> {
                        adapter.submitList(result.data.data)
                        binding.tvCount.text           = "${result.data.pagination.total} clients"
                        binding.layoutError.visibility = View.GONE
                    }
                    is Result.Error -> {
                        binding.tvErrorMsg.text        = result.message
                        binding.layoutError.visibility = View.VISIBLE
                    }
                }
            }
        }
    }

    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
