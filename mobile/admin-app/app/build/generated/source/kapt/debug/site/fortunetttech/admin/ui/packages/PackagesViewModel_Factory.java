package site.fortunetttech.admin.ui.packages;

import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.admin.data.repository.PackageRepository;

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
public final class PackagesViewModel_Factory implements Factory<PackagesViewModel> {
  private final Provider<PackageRepository> repoProvider;

  public PackagesViewModel_Factory(Provider<PackageRepository> repoProvider) {
    this.repoProvider = repoProvider;
  }

  @Override
  public PackagesViewModel get() {
    return newInstance(repoProvider.get());
  }

  public static PackagesViewModel_Factory create(Provider<PackageRepository> repoProvider) {
    return new PackagesViewModel_Factory(repoProvider);
  }

  public static PackagesViewModel newInstance(PackageRepository repo) {
    return new PackagesViewModel(repo);
  }
}
