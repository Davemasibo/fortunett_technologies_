package site.fortunetttech.admin.ui.vouchers

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import site.fortunetttech.admin.R
import site.fortunetttech.admin.data.model.Voucher
import site.fortunetttech.admin.databinding.ItemVoucherBinding

class VoucherAdapter(
    private val onDelete: (Voucher) -> Unit
) : ListAdapter<Voucher, VoucherAdapter.ViewHolder>(DIFF) {

    inner class ViewHolder(private val b: ItemVoucherBinding) : RecyclerView.ViewHolder(b.root) {
        fun bind(v: Voucher) {
            b.tvCode.text    = v.voucher_code
            b.tvPackage.text = v.package_name ?: "No package"
            b.tvStatus.text  = v.status.replaceFirstChar { it.uppercase() }
            b.tvPrice.text   = "KSH %.0f".format(v.price)
            b.tvExpiry.text  = v.expiry_date?.take(10) ?: "No expiry"
            b.tvUsedBy.text  = if (v.used_by_name != null) "Used by: ${v.used_by_name}" else ""
            val statusColor = when (v.status) {
                "active"  -> R.color.status_active
                "used"    -> R.color.status_inactive
                else      -> R.color.status_expired
            }
            b.tvStatus.setTextColor(ContextCompat.getColor(b.root.context, statusColor))
            b.btnDelete.isEnabled = v.status != "used"
            b.btnDelete.setOnClickListener { onDelete(v) }
        }
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int) = ViewHolder(
        ItemVoucherBinding.inflate(LayoutInflater.from(parent.context), parent, false)
    )

    override fun onBindViewHolder(holder: ViewHolder, position: Int) = holder.bind(getItem(position))

    companion object {
        private val DIFF = object : DiffUtil.ItemCallback<Voucher>() {
            override fun areItemsTheSame(a: Voucher, b: Voucher) = a.id == b.id
            override fun areContentsTheSame(a: Voucher, b: Voucher) = a == b
        }
    }
}
