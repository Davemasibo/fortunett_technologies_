package site.fortunetttech.customer.ui.history

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import site.fortunetttech.customer.R
import site.fortunetttech.customer.data.model.Payment
import site.fortunetttech.customer.databinding.ItemPaymentBinding

class PaymentAdapter : ListAdapter<Payment, PaymentAdapter.ViewHolder>(DIFF) {

    inner class ViewHolder(private val b: ItemPaymentBinding) : RecyclerView.ViewHolder(b.root) {
        fun bind(p: Payment) {
            b.tvAmount.text  = "KSH %.0f".format(p.amount)
            b.tvMethod.text  = p.method.uppercase()
            b.tvDate.text    = p.created_at.take(10)
            b.tvStatus.text  = p.status.replaceFirstChar { it.uppercase() }
            b.tvStatus.setTextColor(ContextCompat.getColor(b.root.context,
                if (p.status == "completed") R.color.status_success else R.color.status_pending
            ))
        }
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int) = ViewHolder(
        ItemPaymentBinding.inflate(LayoutInflater.from(parent.context), parent, false)
    )

    override fun onBindViewHolder(holder: ViewHolder, position: Int) = holder.bind(getItem(position))

    companion object {
        private val DIFF = object : DiffUtil.ItemCallback<Payment>() {
            override fun areItemsTheSame(a: Payment, b: Payment) = a.id == b.id
            override fun areContentsTheSame(a: Payment, b: Payment) = a == b
        }
    }
}
