package site.fortunetttech.admin.ui.clients;

import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.admin.data.repository.ClientRepository;

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
public final class ClientListViewModel_Factory implements Factory<ClientListViewModel> {
  private final Provider<ClientRepository> repoProvider;

  public ClientListViewModel_Factory(Provider<ClientRepository> repoProvider) {
    this.repoProvider = repoProvider;
  }

  @Override
  public ClientListViewModel get() {
    return newInstance(repoProvider.get());
  }

  public static ClientListViewModel_Factory create(Provider<ClientRepository> repoProvider) {
    return new ClientListViewModel_Factory(repoProvider);
  }

  public static ClientListViewModel newInstance(ClientRepository repo) {
    return new ClientListViewModel(repo);
  }
}
