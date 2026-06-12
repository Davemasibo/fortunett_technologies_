package site.fortunetttech.admin.ui.clients;

import androidx.lifecycle.SavedStateHandle;
import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.admin.data.repository.ClientRepository;
import site.fortunetttech.admin.data.repository.PackageRepository;
import site.fortunetttech.admin.data.repository.PaymentRepository;

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
public final class ClientDetailViewModel_Factory implements Factory<ClientDetailViewModel> {
  private final Provider<ClientRepository> clientRepoProvider;

  private final Provider<PackageRepository> pkgRepoProvider;

  private final Provider<PaymentRepository> payRepoProvider;

  private final Provider<SavedStateHandle> savedStateProvider;

  public ClientDetailViewModel_Factory(Provider<ClientRepository> clientRepoProvider,
      Provider<PackageRepository> pkgRepoProvider, Provider<PaymentRepository> payRepoProvider,
      Provider<SavedStateHandle> savedStateProvider) {
    this.clientRepoProvider = clientRepoProvider;
    this.pkgRepoProvider = pkgRepoProvider;
    this.payRepoProvider = payRepoProvider;
    this.savedStateProvider = savedStateProvider;
  }

  @Override
  public ClientDetailViewModel get() {
    return newInstance(clientRepoProvider.get(), pkgRepoProvider.get(), payRepoProvider.get(), savedStateProvider.get());
  }

  public static ClientDetailViewModel_Factory create(Provider<ClientRepository> clientRepoProvider,
      Provider<PackageRepository> pkgRepoProvider, Provider<PaymentRepository> payRepoProvider,
      Provider<SavedStateHandle> savedStateProvider) {
    return new ClientDetailViewModel_Factory(clientRepoProvider, pkgRepoProvider, payRepoProvider, savedStateProvider);
  }

  public static ClientDetailViewModel newInstance(ClientRepository clientRepo,
      PackageRepository pkgRepo, PaymentRepository payRepo, SavedStateHandle savedState) {
    return new ClientDetailViewModel(clientRepo, pkgRepo, payRepo, savedState);
  }
}
