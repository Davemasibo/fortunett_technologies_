package site.fortunetttech.admin.ui.payments

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
import site.fortunetttech.admin.databinding.FragmentPaymentsBinding
import site.fortunetttech.admin.util.Result

@AndroidEntryPoint
class PaymentsFragment : Fragment() {

    private var _binding: FragmentPaymentsBinding? = null
    private val binding get() = _binding!!
    private val vm: PaymentsViewModel by viewModels()
    private val adapter = PaymentAdapter()

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentPaymentsBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.recyclerView.adapter       = adapter
        binding.recyclerView.layoutManager = LinearLayoutManager(requireContext())
        binding.swipeRefresh.setOnRefreshListener { vm.load() }
        binding.btnRetry.setOnClickListener { vm.load() }

        lifecycleScope.launch {
            vm.payments.collectLatest { result ->
                binding.swipeRefresh.isRefreshing = false
                when (result) {
                    null -> {}
                    is Result.Success -> {
                        val d = result.data
                        adapter.submitList(d.data)
                        binding.tvSummary.text          = "${d.total} payments · KSH %.0f total".format(d.totalAmount)
                        binding.layoutError.visibility  = View.GONE
                        binding.tvEmpty.visibility      = if (d.data.isEmpty()) View.VISIBLE else View.GONE
                    }
                    is Result.Error -> {
                        binding.tvErrorMsg.text         = result.message
                        binding.layoutError.visibility  = View.VISIBLE
                        binding.tvEmpty.visibility      = View.GONE
                    }
                }
            }
        }
    }

    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
