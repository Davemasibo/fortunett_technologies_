package site.fortunetttech.admin.ui.clients;

import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.admin.data.repository.ClientRepository;
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
public final class AddClientViewModel_Factory implements Factory<AddClientViewModel> {
  private final Provider<ClientRepository> clientRepoProvider;

  private final Provider<PackageRepository> pkgRepoProvider;

  public AddClientViewModel_Factory(Provider<ClientRepository> clientRepoProvider,
      Provider<PackageRepository> pkgRepoProvider) {
    this.clientRepoProvider = clientRepoProvider;
    this.pkgRepoProvider = pkgRepoProvider;
  }

  @Override
  public AddClientViewModel get() {
    return newInstance(clientRepoProvider.get(), pkgRepoProvider.get());
  }

  public static AddClientViewModel_Factory create(Provider<ClientRepository> clientRepoProvider,
      Provider<PackageRepository> pkgRepoProvider) {
    return new AddClientViewModel_Factory(clientRepoProvider, pkgRepoProvider);
  }

  public static AddClientViewModel newInstance(ClientRepository clientRepo,
      PackageRepository pkgRepo) {
    return new AddClientViewModel(clientRepo, pkgRepo);
  }
}
