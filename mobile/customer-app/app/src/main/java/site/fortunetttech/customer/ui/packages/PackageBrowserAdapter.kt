package site.fortunetttech.customer.ui.packages

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import site.fortunetttech.customer.data.model.AvailablePackage
import site.fortunetttech.customer.databinding.ItemAvailablePackageBinding

class PackageBrowserAdapter(
    private val onSelect: (AvailablePackage) -> Unit
) : ListAdapter<AvailablePackage, PackageBrowserAdapter.VH>(DIFF) {

    inner class VH(private val b: ItemAvailablePackageBinding) : RecyclerView.ViewHolder(b.root) {
        fun bind(pkg: AvailablePackage) {
            b.tvName.text    = pkg.name
            b.tvPrice.text   = "KSH ${pkg.price.toLong()}"
            b.tvSpeed.text   = "${pkg.download_speed} Mbps ↓ / ${pkg.upload_speed} Mbps ↑"
            b.tvValidity.text = "${pkg.validity_value} ${pkg.validity_unit}"
            b.tvType.text    = pkg.connection_type.uppercase()
            if (pkg.description.isNullOrBlank()) {
                b.tvDescription.visibility = android.view.View.GONE
            } else {
                b.tvDescription.visibility = android.view.View.VISIBLE
                b.tvDescription.text = pkg.description
            }
            b.btnSelect.setOnClickListener { onSelect(pkg) }
        }
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int) = VH(
        ItemAvailablePackageBinding.inflate(LayoutInflater.from(parent.context), parent, false)
    )

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(getItem(position))

    companion object {
        private val DIFF = object : DiffUtil.ItemCallback<AvailablePackage>() {
            override fun areItemsTheSame(a: AvailablePackage, b: AvailablePackage) = a.id == b.id
            override fun areContentsTheSame(a: AvailablePackage, b: AvailablePackage) = a == b
        }
    }
}
