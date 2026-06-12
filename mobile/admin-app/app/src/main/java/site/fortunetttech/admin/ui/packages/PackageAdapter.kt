package site.fortunetttech.admin.ui.packages

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import site.fortunetttech.admin.data.model.Package
import site.fortunetttech.admin.databinding.ItemPackageBinding

class PackageAdapter(
    private val onEdit:   (Package) -> Unit,
    private val onDelete: (Package) -> Unit
) : ListAdapter<Package, PackageAdapter.ViewHolder>(DIFF) {

    inner class ViewHolder(private val b: ItemPackageBinding) : RecyclerView.ViewHolder(b.root) {
        fun bind(pkg: Package) {
            b.tvPackageName.text = pkg.name
            b.tvPackagePrice.text = "KSH %.0f".format(pkg.price)
            val speedText = "${pkg.download_speed}↓ / ${pkg.upload_speed}↑ Mbps"
            b.tvPackageSpeed.text = speedText
            b.tvPackageValidity.text = "${pkg.validity_value} ${pkg.validity_unit}"
            b.tvPackageType.text = pkg.connection_type.uppercase()
            b.tvPackageStatus.text = pkg.status.replaceFirstChar { it.uppercase() }
            b.tvPackageStatus.setTextColor(
                b.root.context.getColor(
                    if (pkg.status == "active") android.R.color.holo_green_dark
                    else android.R.color.holo_red_dark
                )
            )
            b.btnEdit.setOnClickListener   { onEdit(pkg) }
            b.btnDelete.setOnClickListener { onDelete(pkg) }
        }
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int) = ViewHolder(
        ItemPackageBinding.inflate(LayoutInflater.from(parent.context), parent, false)
    )

    override fun onBindViewHolder(holder: ViewHolder, position: Int) = holder.bind(getItem(position))

    companion object {
        private val DIFF = object : DiffUtil.ItemCallback<Package>() {
            override fun areItemsTheSame(a: Package, b: Package) = a.id == b.id
            override fun areContentsTheSame(a: Package, b: Package) = a == b
        }
    }
}
