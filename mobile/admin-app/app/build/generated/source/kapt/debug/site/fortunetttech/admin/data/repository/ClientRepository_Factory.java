package site.fortunetttech.admin.data.repository;

import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.admin.data.network.ApiService;

@ScopeMetadata("javax.inject.Singleton")
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
public final class ClientRepository_Factory implements Factory<ClientRepository> {
  private final Provider<ApiService> apiProvider;

  public ClientRepository_Factory(Provider<ApiService> apiProvider) {
    this.apiProvider = apiProvider;
  }

  @Override
  public ClientRepository get() {
    return newInstance(apiProvider.get());
  }

  public static ClientRepository_Factory create(Provider<ApiService> apiProvider) {
    return new ClientRepository_Factory(apiProvider);
  }

  public static ClientRepository newInstance(ApiService api) {
    return new ClientRepository(api);
  }
}
