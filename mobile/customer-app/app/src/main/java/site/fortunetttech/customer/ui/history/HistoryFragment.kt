package site.fortunetttech.customer.ui.history

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
import site.fortunetttech.customer.databinding.FragmentHistoryBinding
import site.fortunetttech.customer.util.Result

@AndroidEntryPoint
class HistoryFragment : Fragment() {

    private var _binding: FragmentHistoryBinding? = null
    private val binding get() = _binding!!
    private val vm: HistoryViewModel by viewModels()
    private val adapter = PaymentAdapter()

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentHistoryBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.recyclerView.adapter       = adapter
        binding.recyclerView.layoutManager = LinearLayoutManager(requireContext())
        binding.swipeRefresh.setOnRefreshListener { vm.load() }

        lifecycleScope.launch {
            vm.profile.collectLatest { result ->
                binding.swipeRefresh.isRefreshing = false
                when (result) {
                    null -> {}
                    is Result.Success -> {
                        adapter.submitList(result.data.recent_payments)
                        binding.tvEmpty.visibility     = if (result.data.recent_payments.isEmpty()) View.VISIBLE else View.GONE
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
