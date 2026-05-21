package site.fortunetttech.admin.ui.payments

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import site.fortunetttech.admin.R
import site.fortunetttech.admin.data.model.AdminPayment
import site.fortunetttech.admin.databinding.ItemPaymentBinding

class PaymentAdapter : ListAdapter<AdminPayment, PaymentAdapter.ViewHolder>(DIFF) {

    inner class ViewHolder(private val b: ItemPaymentBinding) : RecyclerView.ViewHolder(b.root) {
        fun bind(p: AdminPayment) {
            b.tvClientName.text = p.clientName ?: "Unknown"
            b.tvAmount.text     = "KSH %.0f".format(p.amount)
            b.tvMethod.text     = p.paymentMethod.uppercase()
            b.tvDate.text       = p.paymentDate.take(10)
            b.tvStatus.text     = p.status.replaceFirstChar { it.uppercase() }
            val isCompleted = p.status == "completed"
            val color = if (isCompleted) R.color.status_active else R.color.status_suspended
            b.tvStatus.setTextColor(ContextCompat.getColor(b.root.context, color))
            b.viewStatusBar.setBackgroundColor(ContextCompat.getColor(b.root.context, color))
        }
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int) = ViewHolder(
        ItemPaymentBinding.inflate(LayoutInflater.from(parent.context), parent, false)
    )
    override fun onBindViewHolder(holder: ViewHolder, position: Int) = holder.bind(getItem(position))

    companion object {
        private val DIFF = object : DiffUtil.ItemCallback<AdminPayment>() {
            override fun areItemsTheSame(a: AdminPayment, b: AdminPayment) = a.id == b.id
            override fun areContentsTheSame(a: AdminPayment, b: AdminPayment) = a == b
        }
    }
}
