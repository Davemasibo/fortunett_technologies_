package site.fortunetttech.customer.ui.packages;

import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.customer.data.repository.PackagesRepository;

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
public final class PackagesBrowserViewModel_Factory implements Factory<PackagesBrowserViewModel> {
  private final Provider<PackagesRepository> repoProvider;

  public PackagesBrowserViewModel_Factory(Provider<PackagesRepository> repoProvider) {
    this.repoProvider = repoProvider;
  }

  @Override
  public PackagesBrowserViewModel get() {
    return newInstance(repoProvider.get());
  }

  public static PackagesBrowserViewModel_Factory create(Provider<PackagesRepository> repoProvider) {
    return new PackagesBrowserViewModel_Factory(repoProvider);
  }

  public static PackagesBrowserViewModel newInstance(PackagesRepository repo) {
    return new PackagesBrowserViewModel(repo);
  }
}
