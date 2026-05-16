package site.fortunetttech.admin.ui.dashboard

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import androidx.fragment.app.viewModels
import androidx.lifecycle.lifecycleScope
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch
import site.fortunetttech.admin.databinding.FragmentDashboardBinding
import site.fortunetttech.admin.util.Result

@AndroidEntryPoint
class DashboardFragment : Fragment() {

    private var _binding: FragmentDashboardBinding? = null
    private val binding get() = _binding!!
    private val vm: DashboardViewModel by viewModels()

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentDashboardBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.swipeRefresh.setOnRefreshListener { vm.load() }
        binding.btnRetry.setOnClickListener { vm.load() }

        lifecycleScope.launch {
            vm.stats.collectLatest { result ->
                binding.swipeRefresh.isRefreshing = false
                when (result) {
                    null -> {}
                    is Result.Success -> {
                        val s = result.data
                        binding.tvTotalClients.text  = s.clients.total.toString()
                        binding.tvActiveClients.text = s.clients.active.toString()
                        binding.tvRevenue.text       = "KSH %.0f".format(s.revenue.this_month)
                        binding.tvRouters.text       = "${s.routers.online}/${s.routers.total}"
                        binding.layoutContent.visibility = View.VISIBLE
                        binding.layoutError.visibility   = View.GONE
                    }
                    is Result.Error -> {
                        binding.tvErrorMsg.text          = result.message
                        binding.layoutError.visibility   = View.VISIBLE
                        binding.layoutContent.visibility = View.GONE
                    }
                }
            }
        }
    }

    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
