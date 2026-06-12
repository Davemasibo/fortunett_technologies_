package site.fortunetttech.customer.ui.packages

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.os.bundleOf
import androidx.fragment.app.Fragment
import androidx.fragment.app.viewModels
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import androidx.recyclerview.widget.LinearLayoutManager
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch
import site.fortunetttech.customer.R
import site.fortunetttech.customer.databinding.FragmentPackagesBrowserBinding

@AndroidEntryPoint
class PackagesBrowserFragment : Fragment() {

    private var _binding: FragmentPackagesBrowserBinding? = null
    private val binding get() = _binding!!
    private val vm: PackagesBrowserViewModel by viewModels()
    private lateinit var adapter: PackageBrowserAdapter

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentPackagesBrowserBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        adapter = PackageBrowserAdapter { pkg ->
            findNavController().navigate(
                R.id.payFragment,
                bundleOf("packageId" to pkg.id)
            )
        }
        binding.recyclerView.layoutManager = LinearLayoutManager(requireContext())
        binding.recyclerView.adapter = adapter

        binding.swipeRefresh.setOnRefreshListener { vm.load() }

        binding.chipAll.setOnClickListener     { vm.setFilter(null);       refreshList() }
        binding.chipPppoe.setOnClickListener   { vm.setFilter("pppoe");    refreshList() }
        binding.chipHotspot.setOnClickListener { vm.setFilter("hotspot");  refreshList() }

        lifecycleScope.launch {
            vm.loading.collectLatest { binding.swipeRefresh.isRefreshing = it }
        }
        lifecycleScope.launch {
            vm.packages.collectLatest { refreshList() }
        }
        lifecycleScope.launch {
            vm.error.collectLatest { err ->
                binding.tvError.text       = err ?: ""
                binding.tvError.visibility = if (err != null) View.VISIBLE else View.GONE
            }
        }
    }

    private fun refreshList() {
        val list = vm.filtered()
        adapter.submitList(list)
        binding.tvEmpty.visibility = if (list.isEmpty() && !vm.loading.value) View.VISIBLE else View.GONE
    }

    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
