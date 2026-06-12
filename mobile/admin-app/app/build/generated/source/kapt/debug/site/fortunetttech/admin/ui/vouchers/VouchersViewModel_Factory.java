package site.fortunetttech.admin.ui.vouchers;

import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.admin.data.repository.PackageRepository;
import site.fortunetttech.admin.data.repository.VoucherRepository;

@ScopeMetadata
@QualifierMetadata
@DaggerGenerated
@Generated(
    value = "dagger.internal.codegen.ComponentProcessor",
    comments = "https://dagger.dev"
)
@SuppressWarnings({
    "unchecked",
    "rawtypes",
    "KotlinInternal",
    "KotlinInternalInJava",
    "cast"
})
public final class VouchersViewModel_Factory implements Factory<VouchersViewModel> {
  private final Provider<VoucherRepository> repoProvider;

  private final Provider<PackageRepository> pkgRepoProvider;

  public VouchersViewModel_Factory(Provider<VoucherRepository> repoProvider,
      Provider<PackageRepository> pkgRepoProvider) {
    this.repoProvider = repoProvider;
    this.pkgRepoProvider = pkgRepoProvider;
  }

  @Override
  public VouchersViewModel get() {
    return newInstance(repoProvider.get(), pkgRepoProvider.get());
  }

  public static VouchersViewModel_Factory create(Provider<VoucherRepository> repoProvider,
      Provider<PackageRepository> pkgRepoProvider) {
    return new VouchersViewModel_Factory(repoProvider, pkgRepoProvider);
  }

  public static VouchersViewModel newInstance(VoucherRepository repo, PackageRepository pkgRepo) {
    return new VouchersViewModel(repo, pkgRepo);
  }
}
