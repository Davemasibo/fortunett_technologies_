package site.fortunetttech.admin.ui.routers

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import androidx.fragment.app.viewModels
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch
import site.fortunetttech.admin.databinding.FragmentRoutersBinding
import site.fortunetttech.admin.util.Result

@AndroidEntryPoint
class RoutersFragment : Fragment() {

    private var _binding: FragmentRoutersBinding? = null
    private val binding get() = _binding!!
    private val vm: RoutersViewModel by viewModels()
    private val adapter = RouterAdapter()

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentRoutersBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.recyclerView.adapter       = adapter
        binding.recyclerView.layoutManager = LinearLayoutManager(requireContext())
        binding.swipeRefresh.setOnRefreshListener { vm.load() }
        binding.btnRetry.setOnClickListener { vm.load() }

        lifecycleScope.launch {
            vm.routers.collectLatest { result ->
                binding.swipeRefresh.isRefreshing = false
                when (result) {
                    null -> {}
                    is Result.Success -> {
                        val routers = result.data.data
                        adapter.submitList(routers)
                        val online = routers.count { it.status == "online" }
                        binding.tvCount.text           = "$online/${routers.size} online"
                        binding.layoutError.visibility = View.GONE
                        binding.tvEmpty.visibility     = if (routers.isEmpty()) View.VISIBLE else View.GONE
                    }
                    is Result.Error -> {
                        binding.tvErrorMsg.text        = result.message
                        binding.layoutError.visibility = View.VISIBLE
                        binding.tvEmpty.visibility     = View.GONE
                    }
                }
            }
        }
    }

    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
